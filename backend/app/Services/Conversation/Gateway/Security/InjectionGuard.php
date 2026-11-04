<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Security;

use Normalizer;
use Transliterator;

/**
 * Capa HEURÍSTICA de detección de instrucciones inyectadas en texto recuperado (nombres de productos, zonas, segmentos, notas, eventos externos…) antes de que llegue al
 * modelo (V2.5 · W6-D; ADR-V25-17; V25-GWY-016).
 *
 * ESTO NO ES LA GARANTÍA. Es una capa de detección y de retención oportunista: un atacante con tiempo puede reformular, usar otro idioma o partir la instrucción entre campos. Las
 * garantías son ESTRUCTURALES y no dependen de este archivo:
 *   1. el modelo no puede elegir empresa, usuario, alcance ni habilidades (salen de la petición; las claves reservadas se rechazan);
 *   2. las herramientas son de SOLO LECTURA (no hay nada que una instrucción inyectada pueda ejecutar, escribir, aprobar ni enviar);
 *   3. el dato viaja marcado como dato (mensajes de rol «tool») y nunca en el canal de instrucciones;
 *   4. las cifras de la respuesta deben provenir de valores numéricos de las herramientas (NumericGrounding), no del texto libre;
 *   5. los campos tipo nombre se saneán estructuralmente (sin marcado ni caracteres de control, espacios colapsados, longitud acotada).
 *
 * Qué hace esta clase: normaliza solo para comparar (caracteres de formato fuera, NFKC, transliteración a ASCII —ICU si existe, y un mapa de confusables si no—, minúsculas,
 * sin tildes, unión de guiones de fin de línea), genera variantes para las ofuscaciones más comunes (letras separadas, leet, invertido, ROT13, entidades/%XX/\uXXXX, Base64 y hex
 * incrustados, errores de dedo en las palabras clave) y contrasta cada una con familias de patrones en español e inglés. Los patrones exigen ESTRUCTURA de instrucción (verbo + objeto,
 * o los marcadores de plantilla de chat): una palabra técnica suelta («bypass», «drop table», «system:») no basta. Un valor sospechoso se RETIENE: se sustituye por un marcador fijo y la
 * llamada se registra como `blocked_injection`; el resto del resultado sigue disponible.
 *
 * Riesgo residual (medido y documentado en docs/architecture/INTELLIGENCE_GATEWAY.md): paráfrasis no previstas, otros idiomas, instrucciones repartidas en varios campos y codificaciones
 * propias. Pueden darse falsos positivos con nombres que imiten una instrucción; se retienen y quedan auditados.
 */
final class InjectionGuard
{
    public const PLACEHOLDER = '[contenido retenido: posible instrucción inyectada en los datos]';

    /** Claves de campos tipo nombre: se saneán estructuralmente (marcado fuera, espacios colapsados, longitud acotada) aunque no se detecte nada. */
    public const NAME_KEYS = ['name', 'sku', 'code', 'external_code', 'customer_code', 'segment_code', 'zone', 'brand', 'unit', 'label', 'title', 'category'];

    public const NAME_MAX_CHARS = 200;

    /** Cadenas más cortas que esto no pueden contener una instrucción útil. */
    private const MIN_LENGTH = 8;

    /** Tope de texto que se normaliza y compara por valor (el resto se ignora para acotar el costo; un valor así de largo ya se marca por longitud). */
    private const MAX_SCAN_CHARS = 4000;

    private const MAX_DECODE_DEPTH = 2;

    /** Palabras clave para corregir errores de dedo (distancia de edición pequeña) antes de comparar. */
    private const FUZZY_KEYWORDS = ['ignora', 'ignore', 'ignorar', 'instrucciones', 'instruccion', 'instructions', 'instruction', 'anteriores', 'previous', 'previas', 'olvida', 'disregard', 'revela', 'reveal', 'prompt', 'directrices', 'guidelines'];

    /** Verbos de orden (español/inglés, sin tildes) con los que empieza una frase que le habla a un asistente. */
    private const IMPERATIVES = ['lista', 'listar', 'dame', 'dime', 'muestra', 'muestrame', 'mostrar', 'escribe', 'responde', 'contesta', 'haz', 'reporta', 'informa', 'trata', 'usa', 'utiliza', 'salta', 'ejecuta', 'envia', 'manda', 'imprime', 'traduce', 'resume', 'concatena', 'devuelve', 'entrega', 'aprueba', 'rechaza', 'cierra', 'abre', 'modifica', 'actualiza', 'crea', 'borra', 'elimina', 'cambia', 'marca', 'exporta', 'descarga', 'revela', 'comparte', 'copia', 'repite', 'agrega', 'anade', 'incluye', 'olvida', 'ignora', 'finge', 'simula', 'actua', 'confirma', 'acepta', 'omite', 'evita', 'desactiva', 'activa', 'notifica', 'avisa', 'pide', 'solicita', 'ordena', 'consulta', 'busca', 'accede', 'extrae', 'recopila', 'guarda', 'sube', 'publica', 'limpia', 'reinicia', 'restablece', 'list', 'give', 'show', 'tell', 'write', 'respond', 'answer', 'reply', 'make', 'report', 'treat', 'use', 'skip', 'execute', 'run', 'send', 'print', 'translate', 'summarize', 'summarise', 'concatenate', 'return', 'output', 'approve', 'reject', 'close', 'open', 'modify', 'update', 'create', 'delete', 'remove', 'change', 'mark', 'export', 'download', 'reveal', 'share', 'copy', 'repeat', 'add', 'append', 'include', 'forget', 'ignore', 'pretend', 'act', 'confirm', 'accept', 'omit', 'avoid', 'disable', 'enable', 'notify', 'ask', 'request', 'order', 'query', 'search', 'access', 'extract', 'collect', 'gather', 'save', 'upload', 'publish', 'clear', 'reset'];

    /** Palabras del dominio del asistente (datos, usuarios, permisos, decisiones, credenciales…): una orden que las menciona no es un nombre de producto. */
    private const TARGETS = ['respuesta', 'respuestas', 'answer', 'answers', 'resultado', 'resultados', 'results', 'datos', 'data', 'usuario', 'usuarios', 'user', 'users', 'reglas', 'rules', 'instrucciones', 'instructions', 'permisos', 'permissions', 'decisiones', 'decisions', 'recomendaciones', 'recommendations', 'empresas', 'tenant', 'tenants', 'historial', 'history', 'contexto', 'context', 'sesion', 'session', 'herramienta', 'herramientas', 'tool', 'tools', 'pronostico', 'forecast', 'margenes', 'margins', 'costos', 'costs', 'correos', 'emails', 'email', 'contrasenas', 'passwords', 'password', 'claves', 'keys', 'tokens', 'token', 'cedulas', 'ids', 'nombres', 'names', 'compradores', 'buyers', 'clientes', 'customers', 'auditoria', 'audit', 'registros', 'records', 'database', 'esquema', 'schema', 'configuracion', 'configuration', 'limites', 'limits'];

    /** Preámbulos que se descartan antes de buscar el verbo de orden. */
    private const FILLERS = ['por favor', 'please', 'hola asistente', 'hello assistant', 'hola', 'hello', 'hey', 'oye', 'asistente', 'assistant', 'ahora', 'now', 'then', 'luego', 'despues', 'entonces', 'primero', 'first', 'y', 'and', 'ok', 'vale', 'tambien', 'also', 'finalmente', 'finally'];

    /** Confusables comunes (cirílico, griego, alfabetos fonéticos, mayúsculas pequeñas, cherokee…) → latín, solo para comparar. */
    private const CONFUSABLES = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ѕ' => 's', 'ј' => 'j', 'ԁ' => 'd', 'һ' => 'h', 'ո' => 'n', 'т' => 't', 'п' => 'n', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'в' => 'b', 'г' => 'r', 'ь' => 'b', 'ӏ' => 'l', 'ԛ' => 'q', 'ԝ' => 'w', 'ѵ' => 'v',
        'ο' => 'o', 'α' => 'a', 'ε' => 'e', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'η' => 'n', 'μ' => 'u', 'β' => 'b', 'γ' => 'y',
        'ı' => 'i', 'ɡ' => 'g', 'ɑ' => 'a', 'ɪ' => 'i', 'ɢ' => 'g', 'ɴ' => 'n', 'ᴏ' => 'o', 'ʀ' => 'r', 'ᴇ' => 'e', 'ᴀ' => 'a', 'ʙ' => 'b', 'ᴄ' => 'c', 'ᴅ' => 'd', 'ꜰ' => 'f', 'ʜ' => 'h', 'ᴊ' => 'j', 'ᴋ' => 'k', 'ʟ' => 'l', 'ᴍ' => 'm', 'ᴘ' => 'p', 'ꞯ' => 'q', 'ꜱ' => 's', 'ᴛ' => 't', 'ᴜ' => 'u', 'ᴠ' => 'v', 'ᴡ' => 'w', 'ʏ' => 'y', 'ᴢ' => 'z',
        'Ꭵ' => 'i', 'Ꭺ' => 'a', 'Ꮯ' => 'c', 'Ꭰ' => 'd', 'Ꭼ' => 'e', 'Ꮐ' => 'g', 'Ꮋ' => 'h', 'Ꮶ' => 'k', 'Ꮮ' => 'l', 'Ꮇ' => 'm', 'Ꮲ' => 'p', 'Ꮓ' => 'z', 'Ꮪ' => 's', 'Ꭲ' => 't', 'Ꮩ' => 'v', 'Ꮃ' => 'w',
    ];

    /**
     * Familias de patrones sobre el texto NORMALIZADO (minúsculas, sin tildes, ASCII). id => expresión regular. Todas acotan sus saltos de palabras ({0,N}) para evitar el
     * retroceso explosivo. `\W` ya incluye los saltos de línea en la variante aplanada.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Ignorar/olvidar/no seguir las instrucciones: verbo FUERTE + (hasta 6 palabras) + objeto-instrucción, o verbo DÉBIL + determinante + objeto-instrucción estricto.
        'ignore_instructions' => '~\b(?:ignor\w*|disregard\w*|forget\w*|olvid\w*|desobedec\w*|desestim\w*|haz(?:me)? caso omiso|hacer caso omiso|hagan caso omiso|pasa(?:r|n)? por alto|deja(?:r|n)? de lado|dejar de lado|dejen de lado|no (?:sigas|sigan|obedezcas|obedezcan|respetes|respeten|cumplas|hagas caso|tengas en cuenta|tomes en cuenta|uses|apliques)|do not (?:follow|obey|comply with|respect|heed|apply)|don.?t (?:follow|obey|comply with|heed)|stop (?:following|obeying|respecting)|pay no (?:attention|heed) to|set aside|put aside|throw away|never mind)\b(?:\W+\w+){0,6}?\W+(?:(?:instruccion|instruction|regla|rule|prompt|polic|politic|directiv|directric|guideline|restriccion|restriction|limite|limit|safeguard|guardrail|lo anterior|lo dicho|todo lo (?:anterior|dicho|que)|lo que (?:se te|te|le)\W+(?:\w+\W+){0,2}(?:dicho|indicado|indicaron|dijeron|pidieron|dieron)|what (?:you were|you have been|you.?ve been|was|i)\W+(?:\w+\W+){0,2}(?:told|said|given)|everything above|all (?:of )?the above|safety|content filter|filtros? de contenido|(?:prior|previous|earlier|anterior\w*|above|todo el|all the|the whole)\W+(?:\w+\W+){0,1}(?:context|contexto|conversation|conversacion|history|historial|messages?|mensajes?))\w*)~u',
        'ignore_instructions_weak' => '~\b(?:evit(?:a|e|ar)|omit(?:e|ir|as)|skip(?:ping)?|bypass(?:ing)?|override|overrid(?:e|ing)|anul(?:a|ar|e)|sobrescrib(?:e|ir)|saltate|salta|reemplaz(?:a|ar)|sustituy(?:e|ir)|replace|overwrite|swap|cambia(?:r)?|change)\b(?:\W+\w+){0,2}?\W+(?:tus|tu|sus|su|your|the system|el sistema|las|los|the|these|those|esas|esos|estas|estos|any|cualquier)\W+(?:\w+\W+){0,2}?(?:instruccion\w*|instruction\w*|reglas?|rules?|prompt|directiv\w*|directric\w*|guidelines?|polic\w*|politic\w*|restriccion\w*|restrictions?)~u',
        // Pedir el prompt o los secretos.
        'reveal_secrets' => '~\b(?:reveal|show|print|dump|leak|display|tell|say|repeat|recite|output|copy|share|disclose|expose|read|muestra\w*|mostrar|revela\w*|imprime|filtra\w*|dime|dinos|repite\w*|recita|copia\w*|comparte\w*|devuelve|dame|entrega\w*|lee|envia\w*|send|give me|what is|what.?s|cual es|cuales son|que dice)\b(?:\W+\w+){0,5}?\W+(?:(?:the|el|la|los|las)\W+)?(?:(?:system|initial|original|hidden|secret|internal)\W+prompt|prompt\W+(?:del|de)\W+sistema|prompt\W+(?:inicial|original|oculto|secreto)|instrucciones\W+(?:del|de)\W+sistema|system\W+instructions|api[ _-]?key|clave de api|clave secreta|secret key|contrasena|password|credencial\w*|credentials|variables de entorno|environment variables|access token|token de acceso|(?:your|tu|tus|su|sus)\W+(?:(?:system|initial|original|hidden|secret|internal|inicial|oculto|secreto|interno|del sistema|de sistema)\W+)*(?:prompt|instrucciones|instructions|directivas|directrices|reglas|rules|configuracion|configuration))~u',
        'prompt_extraction' => '~\b(?:repeat|repite\w*|recite|print|imprime|output|copy|copia\w*|echo|transcribe)\b(?:\W+\w+){0,4}?\W+(?:everything|all|todo|the text|the words|el texto|las palabras|lo|the content|el contenido)\W+(?:above|before|previous\w*|anterior\w*|arriba|antes|prior|that came before)|\b(?:verbatim|palabra por palabra|word for word|textualmente)\b(?:\W+\w+){0,6}?\W+(?:including|incluyendo|with)\W+(?:\w+\W+){0,2}(?:rules|reglas|instructions|instrucciones|prompt)|\b(?:repeat|repite|recite)\b[^.]{0,60}\b(?:verbatim|palabra por palabra|word for word)\b~u',
        // Datos de otras empresas.
        'cross_tenant' => '~\b(?:muestra|muestrame|mostrar|dame|entrega|devuelve|consulta|lista|revela|extrae|obten|accede|acceder|show|give me|list|return|query|fetch|access|retrieve|reveal)\b(?:\W+\w+){0,6}?\W+(?:otras? (?:empresas?|companias?|inquilinos?)|other (?:tenants?|compan(?:y|ies)|organizations?)|all tenants?|todas las empresas|another (?:company|tenant)|otro (?:tenant|inquilino))\b~u',
        'tenant_override' => '~\b(?:tenant|empresa|company)[ _]?id\b\s*[:=]~u',
        // Marcadores de plantilla de chat (ChatML, Harmony, Llama 3, Gemma, Mistral, Qwen, DeepSeek) y transcripciones.
        'role_marker' => '~(?:<\|[a-z0-9_ ]{1,40}\|>|\[/?(?:inst|system_prompt|available_tools|tool_calls?|tool_results?)\]|<<\s*/?\s*sys\s*>>|</?(?:s|system|assistant|developer|user|human|bos|eos|unk|pad|start_of_turn|end_of_turn|tool_call|tool_response|function_calls?|function_results?|invoke|parameter)\b[^>\n]{0,80}>|\#{2,3}\s*(?:instruction|system|response|assistant|user)\b|(?:^|\n)\s*(?:system|developer|sistema|desarrollador)\s*:\s*(?:you |your |tu |tus |eres |ignor|do not|don.?t|no |from now|a partir|respond|always|siempre|never|nunca|must|debes|the user|new |nuevo|nueva|reveal|revela|output|print|say |di |list|lista|override|disregard|forget|olvida|act |actua|answer|contesta|obey|obedece|follow|sigue)|(?:^|\n)\s*(?:human|user|usuario)\s*:[^\n]*\n+\s*(?:assistant|asistente|ai|bot)\s*:)~u',
        'structured_message' => '~(?:"role"\s*:\s*"(?:system|assistant|developer|tool|user)"|"(?:tool_calls?|function_call|tool_use|function_response)"\s*:|"type"\s*:\s*"(?:function|tool_use|tool_call)"|(?:^|\n)\s*role\s*:\s*(?:system|assistant|developer|tool)\b|(?:^|\n)\s*(?:tool_calls?|function_call)\s*:)~u',
        // Cambio de rol, modo desarrollador, jailbreak, nuevas instrucciones.
        'new_role' => '~(?:\b(?:a partir de ahora|desde ahora|de ahora en adelante|from now on|starting now|henceforth)\b[,;:]?\s*(?:tus\b|eres (?:un|una|el|la|mi|nuestro)\b|seras\b|actuaras\b|te comportaras\b|debes\b|responde\b|contesta\b|siempre\b|nunca\b|you (?:are|will|must|should|shall)\b|your\b|respond\b|answer\b|always\b|never\b|ignore\b|only\b|solo\b)|\b(?:ahora|now) (?:eres|seras) (?:un|una|el|la|mi|nuestro)\b|\byou are now (?:a|an|the|my|our|no longer)\b|\b(?:actua|actue|actuar|act|behave|respond|pretend|finge|simula|imagina|comportate)\w* (?:como|as|like|que eres|to be|ser) (?:si |un |una |el |la |a |an |the |if )|\b(?:ahora|now) (?:actua|act|behave|comportate)\w* (?:como|as|like)\b|\byour new (?:role|instructions?)\b|\btu nuevo (?:rol|papel)\b|\bnew (?:system )?instructions?\s*:|\bnuevas instrucciones\s*:|\b(?:estas son|these are|here are|the following are|las siguientes son)\W+(?:tus |your )?(?:nuevas|new)\W+(?:instrucciones|instructions|rules|reglas)\b|\b(?:follow|obey|sigue|obedece|apply|aplica)\w*\W+(?:(?:only|solo)\W+)?(?:estas|these|las siguientes|the following|nuevas|new|mis|my)\W+(?:\w+\W+){0,2}(?:instrucciones|instructions|reglas|rules|ordenes|orders|commands)\b|\byou (?:have|has) no (?:restrictions?|rules|limits?|limitations)\b|\bno tienes (?:restricciones|reglas|limites)\b|\bjailbreak(?:ed)?\b|\bdan mode\b|\bdo anything now\b|\bmodo (?:dan|sin restricciones)\b|\b(?:enable|activa\w*|enciende|turn on|enter|entra\w*|switch to|cambia a|pasa a)\W+(?:the\W+|el\W+)?(?:developer|desarrollador|dev|god|dios|admin|debug|jailbreak|dan|root|sudo|maintenance|mantenimiento|unrestricted)\W+(?:mode|modo)\b|\b(?:disable|turn off|desactiva\w*|apaga\w*|deshabilita\w*|quita\w*|elimina\w*|remove)\W+(?:\w+\W+){0,3}(?:(?:content|safety|security|moderation)\W+filters?|filtros?\W+de\W+(?:contenido|seguridad|moderacion)|restrictions|restricciones|safeguards|guardrails|moderation|moderacion)\b)~u',
        'instructions_void' => '~\b(?:instrucciones|reglas|directivas|directrices|instructions|rules|guidelines|prompt)\W+(?:\w+\W+){0,3}(?:quedan?|estan?|son|are|is|have been|has been|were|become|becomes)\W+(?:sin efecto|anulad\w+|void|null\w*|cancel\w+|obsolet\w+|revoked|invalid\w*|overridden|superseded|replaced|derogad\w+)|\b(?:a continuacion se indican|the current ones are|las vigentes son|the new ones are)\b~u',
        // Directivas a herramientas y SQL (con estructura).
        'tool_directive' => '~\b(?:llama|llamar|invoca|invocar|ejecuta|ejecutar|utiliza|usa|call|invoke|execute|run|use)\w*\W+(?:a\W+)?(?:la\W+|el\W+|the\W+|esta\W+|this\W+|that\W+|tu\W+|your\W+)(?:siguiente\W+|next\W+)?(?:herramienta|tool|funcion|function|comando|command|api)\b|\b(?:herramienta|tool|funcion|function)\W*[:=]?\W*(?:run_sql|exec\w*|shell|bash|eval|drop\w*|delete\w*|[a-z]+_[a-z_]+)\b|\b(?:call|invoke|run|execute|llama|invoca|ejecuta)\w*\W+(?:[a-z]+_){1,4}[a-z]+\b~u',
        'sql_statement' => '~(?:[;\x27")]\s*(?:drop|truncate)\s+table\b|\b(?:drop|truncate)\s+table\s+(?:if\s+exists\s+)?[\w."]+\s*(?:;|--|/\*)|\bdelete\s+from\s+[\w."]+\s*(?:where\b|;|--)|\binsert\s+into\s+[\w."]+\s*(?:\(|values\b|select\b)|\bunion\s+(?:all\s+)?select\b[^.]{0,80}\bfrom\b|\bselect\s+(?:\*|[\w.,\s]{1,60}?)\s+from\s+[\w."]+|\balter\s+table\s+[\w."]+\s+(?:add|drop|rename|modify|alter)\b|\bupdate\s+[\w."]+\s+set\s+[\w."]+\s*=)~u',
        // Ocultar al usuario, hablarle al usuario, imponer el formato de la respuesta.
        'conceal_from_user' => '~(?:\b(?:no|nunca|jamas|sin)\s+(?:se\s+lo\s+|le\s+)?(?:digas|menciones|cuentes|reveles|informes|avises|comentes)\b(?:\W+\w+){0,3}?\W+(?:usuario|persona|humano|nadie)|\b(?:sin|no)\s+(?:avisar|informar|decir|mencionar|comentar)\W+(?:\w+\W+){0,2}(?:usuario|persona)|\b(?:do not|don.?t|never)\W+(?:tell|inform|mention|notify|reveal)\W+(?:\w+\W+){0,3}(?:user|human|person|anyone|anybody)|\b(?:keep|mantener|manten\w*|guarda\w*)\W+(?:\w+\W+){0,3}(?:secret|secreto)\W+(?:\w+\W+){0,3}(?:from|del|al|de)\W+(?:the\W+)?(?:user|usuario)|\boculta\w*\W+(?:\w+\W+){0,2}(?:al|del)\W+usuario|\bhide\W+(?:this|it)\W+from\b)~u',
        'tell_the_user' => '~(?:\b(?:debe|debes|tienes que|tiene que|you must|you should|you have to|deberias|deberia)\W+(?:\w+\W+){0,2}(?:decir|dile|di|tell|inform\w*|advise|ask|recomendar|recommend|indicar|pedir|pide)\W+(?:\w+\W+){0,2}(?:al|a la|the)\W+(?:usuario|user|cliente|customer|persona|person)|\b(?:tell|ask|instruct|advise|dile|di|decir|pide|pedir|indica|indicale|ordena|recomienda|recommend)\W+(?:\w+\W+){0,2}?(?:al\W+(?:usuario|cliente|comprador)|a\W+la\W+persona|the\W+(?:user|customer|client)|users?)\W+(?:que|to|a)\b|\b(?:cuando|when|whenever|si|if)\W+(?:\w+\W+){0,3}(?:resum\w*|summari[sz]\w*|expliques|explain\w*|respondas|respond\w*|answer\w*|contestes|list\w*|muestres|show\w*|generes|generate\w*|redactes|write)\W+(?:\w+\W+){0,8}(?:agrega|anade|incluye|adjunta|add|append|include|attach|tell|dile|menciona|mention)\b|\b(?:agrega|anade|add|append)\w*\W+(?:\w+\W+){0,3}(?:al final|at the end|to the end)\b|\b(?:begin|start|open|end|finish|comienza\w*|empieza\w*|inicia\w*|termina\w*|finaliza\w*)\W+(?:\w+\W+){0,2}(?:answer|response|reply|respuesta|message|mensaje)\W+(?:with|con)\b|\b(?:respond\w*|reply|answer|contest\w*|escribe|write|say|di)\W+(?:only|just|solely|exclusively|unicamente|solo|solamente|exclusivamente)\W+(?:with|con)\b|\b(?:instead of|en lugar de|en vez de)\W+(?:\w+\W+){0,6}?(?:state|say|tell|write|report|respond|answer|reply|di|diga|digas|decir|escribe|responde|contesta|informa|declara|afirma)\b|\b(?:di|diga|digas|say|state|report|reporta|afirma|declara)\W+(?:que|that)\W+(?:el|la|los|las|the)?\W*(?:\w+\W+){0,2}(?:es|son|sera|seran|is|are|will be)\W+\d)~u',
        'fake_authority' => '~(?:\b(?:system|sistema)\W+(?:message|update|notice|alert|override|prompt|instruction|mensaje|actualizacion|aviso|alerta|instruccion)\w*\s*[:!\-]|\b(?:mensaje|message|aviso|notice|update|actualizacion|alerta|alert)\W+(?:del|de el|of the|from the|de)\W+(?:sistema|system|administrador|admin|desarrollador|developer|equipo de seguridad|security team)\s*[:!\-]|\[(?:system|sistema|admin|developer|assistant|asistente|important|importante)\]|\b(?:policy|politica)\W+(?:changed|has changed|updated|cambio|ha cambiado|se actualizo)\W+(?:\w+\W+){0,3}(?:you must|debes|ahora debes|now)|\b(?:ya |already )?(?:autoriz\w+|authori[sz]ed|aprob\w+|approved)\W+(?:\w+\W+){0,3}(?:exportar|export|eliminar|delete|enviar|send|mostrar|show|revelar|reveal|compartir|share)\W+(?:\w+\W+){0,2}(?:all|todos|todas)|\b(?:proceed|procede|continua|continue|sigue)\W+(?:without|sin)\W+(?:asking|pedir|preguntar|solicitar|confirm\w+|permission|permiso)\b)~u',
        'send_message' => '~\b(?:envia\w*|manda\w*|send|forward|reenvia\w*|email)\b(?:\W+\w+){0,4}?\W+(?:correo|email|e-mail|mensaje|whatsapp|sms|message|mail)\w*(?:\W+\w+){0,4}?\W+(?:a|al|to)\W+\S+~u',
        'addressing_model' => '~\b(?:note|nota|mensaje|message|instruccion|instruction|aviso|important|importante|urgent|urgente)s?\s*(?:to|for|para)\s+(?:the\s+|el\s+|la\s+)?(?:ai|llm|assistant|asistente|gpt|chatbot|bot)\b|(?:^|[.!?:\n] ?)(?:para|to|for)\s+(?:el\s+|la\s+|the\s+)?(?:ai|llm|assistant|asistente|gpt|chatbot)\s*[:,]|\b(?:dear|estimado|hey|oye)\s+(?:ai|llm|assistant|asistente|gpt|chatbot)\b~u',
        // Contenido activo, enlaces y marcado.
        'active_content' => '~(?:!\[[^\]]*\]\s*[(\[]|\[[^\]]{1,300}\]\(\s*(?:https?:|//|javascript:|data:|ftp:|mailto:)|(?:^|\n)\s*\[[^\]]+\]:\s*(?:https?:|//)|<\s*/?\s*(?:img|script|iframe|object|embed|link|meta|svg|style|a|form|base|video|audio|source|input|button|textarea|select|details|marquee|frame|frameset|applet|math|template|canvas)\b|<!--|javascript\s*:|data:text/html|\bon(?:error|load|click|mouseover|focus)\s*=)~u',
        'link_instruction' => '~(?:(?:https?://|www\.)\S+.{0,80}\b(?:visit|visita\w*|abre|abrir|open|click|clic|haz clic|ingresa|ingrese|enter|verifica\w*|verify|descarga\w*|download|login|inicia sesion|sign in)\b|\b(?:visit|visita\w*|abre|abrir|open|click|clic|haz clic|ingresa|ingrese|verifica\w*|verify|descarga\w*|download|inicia sesion|sign in)\b.{0,80}(?:https?://|www\.)\S+)~u',
        // Familias añadidas tras medir sobre frases NO vistas (conjunto retenido): orden de responder/mostrar distinto, autoridad, borrar el contexto, límites de filas.
        'response_shaping' => '~(?:\b(?:always|siempre)\W+(?:answer|respond|reply|say|state|contesta|responde|di|afirma)\b|\b(?:responde|contesta|respond|reply|answer)\W+(?:con|with)\W+(?:la\W+palabra|the\W+word|solo|only)\b|\b(?:no|do not|don.?t)\W+(?:respondas|contestes|answer|reply)\W+(?:\w+\W+){0,4}(?:pregunta|question|lo que|what)|\b(?:en su lugar|instead|in its place)\W+(?:\w+\W+){0,2}(?:muestra|lista|imprime|show|list|dump|print|display)\b|\b(?:escribe|write|di|say|state|put)\W+(?:\w+\W+){0,3}(?:en|in)\W+(?:tu|your)\W+(?:respuesta|answer|response|reply)\W+(?:que|that)\b|\b(?:responderas|contestaras|answer|respond)\W+(?:\w+\W+){0,3}(?:unicamente|solo|only)\W+(?:en|in)\b)~u',
        'exfiltration_request' => '~(?:\b(?:set|put|include|add|write|paste|send|give|provide|share|ponle|incluye|agrega|escribe|pega|envia|entrega|proporciona|ingresa|type|enter)\W+(?:\w+\W+){0,8}?(?:your|tu|tus|su)\W+(?:api\W*key|clave de api|clave secreta|password|contrasena|secret key|token)\b|\b(?:forward|reenvia\w*|reenviar)\W+(?:\w+\W+){0,5}(?:conversation|conversacion|chat|messages?|mensajes?|data|datos|this|esto)\W+(?:\w+\W+){0,2}(?:to|a)\W+\S+@|\b(?:collect|recopila\w*|recolecta\w*|gather|extrae|summari[sz]e|resume|resumir|export\w*|descarga\w*|download)\W+(?:\w+\W+){0,4}(?:all|todos|todas|every)\W+(?:\w+\W+){0,3}(?:tenants?|empresas?|clientes?|customers?|correos|emails|users?|usuarios|phone|telefonos)|\b(?:collect|recopila\w*|recolecta\w*|gather)\W+(?:\w+\W+){0,3}(?:emails?|correos|phone numbers?|telefonos|customer data|datos de los clientes)\b|\b(?:remove|quita\w*|anula\w*|elimina\w*|ignora\w*|ignore|bypass|override|desactiva\w*|disable|lift|levanta\w*|sin)\W+(?:\w+\W+){0,3}(?:row|filas?)\W*(?:limit\w*|limite\w*)|\b(?:limit\w*|limite\w*)\W+(?:de|of)\W+(?:filas|rows)\W+(?:\w+\W+){0,4}(?:impuesto|impuesta|told|given|set)\b|\b(?:print|repeat|output|show|display|imprime|repite|muestra)\W+(?:\w+\W+){0,3}(?:text|content|everything|words|messages?|texto|contenido|todo|mensajes?)\W+(?:\w+\W+){0,3}(?:before|above|prior|preceding|antes|arriba|anterior\w*)\W+(?:\w+\W+){0,1}(?:this|the|este|el)\W+(?:message|line|text|prompt|mensaje|linea|texto)|\b(?:imprime|muestra|repite|print|show|repeat)\W+(?:tu|your)\W+(?:mensaje|message)\W+(?:de|of)\W+(?:sistema|system))~u',
        'authority_claim' => '~(?:\b(?:the user|el usuario|i am|i.?m|soy|yo soy|este usuario|this user)\W+(?:is|es|am|soy)?\W*(?:an?\W+|un\W+|una\W+|the\W+|el\W+)?(?:administrator|administrador|admin|owner|propietario|developer|desarrollador|superuser|root|dueno)\b|\b(?:system|sistema)\W+override\b|\b(?:new|nueva)\W+(?:directive|directiva)\b|\bdirective\W+follows\b|\b(?:reglas|rules|instrucciones|instructions|politicas|policies)\W+(?:\w+\W+){0,3}(?:ya no|no longer|do not|don.?t|no)\W+(?:apply|applies|aplican|aplica)\b|\bwith\W+full\W+(?:rights|access|permissions)\b|\bcon\W+(?:todos los|pleno)\W+(?:permisos|derechos|acceso)\b)~u',
        'context_reset' => '~(?:\b(?:pretend|finge|haz de cuenta|imagine|imagina|act as if|actua como si)\W+(?:\w+\W+){0,5}(?:never happened|did not happen|didn.?t happen|nunca (?:ocurrio|paso|existio|sucedio)|no (?:ocurrio|paso|existio|sucedio))|\b(?:el|la|the)?\W*(?:previous|prior|anterior\w*|above)\W+(?:text|texto|content|contenido|message|mensaje|context|contexto|conversation|conversacion)\W+(?:\w+\W+){0,3}(?:not valid|invalid|void|no es valido|no es valida|ignored|ignorad\w+|irrelevant|irrelevante)\b|\b(?:you are|ya no|no longer)\W+(?:\w+\W+){0,2}(?:no longer|ya no)\W+(?:\w+\W+){0,2}(?:bound|restricted|limited|required|obligated|subject|obligado|sujeto|limitado)\b|\b(?:from this (?:point|moment)|from here|a partir de (?:este momento|aqui)|desde (?:este momento|aqui))\b[,;:]?\s*(?:on\b[,;:]?\s*)?(?:you\b|tu\b|tus\b|responderas|contestaras|answer|respond|siempre|nunca|always|never)|\bnew (?:task|objective|mission)\s*:|\b(?:tu|your)\W+(?:verdadera|real|nueva|new)\W+(?:tarea|mision|task|mission|objective)\b|\b(?:olvida|forget)\W+(?:\w+\W+){0,2}(?:eres|you are)\W+(?:un|una|an?|the)\W+(?:asistente|assistant|ai|bot|modelo|model)\b|\b(?:haz de cuenta|suppose|supon)\W+(?:\w+\W+){0,3}(?:no tienes|you have no|sin)\W+(?:\w+\W+){0,1}(?:restriccion\w*|restriction\w*|limite\w*|limit\w*|regla\w*|rule\w*)|\b(?:desatiend\w*|set aside|put aside)\W+(?:\w+\W+){0,4}(?:tus|your|las|the)\W+(?:reglas|rules|instrucciones|instructions)\b)~u',
        'decode_and_follow' => '~\b(?:decode|decodifica|base64|rot13)\b.{0,40}\b(?:and|y)\b.{0,30}\b(?:follow|execute|run|obey|ejecuta|sigue|obedece)~u',
        'prompt_delimiter' => '~(?:```|\~{3})\s*(?:system|prompt|instructions?)\b|-{3,}\s*(?:end|begin|start|fin|inicio)\s+(?:of\s+|de\s+(?:las\s+)?)?(?:system|prompt|instructions?|instrucciones|context|contexto)\b~u',
    ];

    /** @var list<string> expresiones adicionales de la configuración (`conversation.gateway.injection.extra_patterns`) */
    private array $extra;

    /** @var array<string, list<string>> resultados ya calculados de esta instancia (los mismos textos se repiten mucho en un resultado) */
    private array $memo = [];

    /** @var bool usar la transliteración de ICU (`Any-Latin; Latin-ASCII`) además del mapa de confusables; false = solo el mapa (prueba del camino sin la extensión intl) */
    private bool $icu;

    /**
     * @param  list<string>|null  $extra  null = configuración
     * @param  bool|null  $icu  null = usar ICU si la extensión intl está disponible
     */
    public function __construct(?array $extra = null, ?bool $icu = null)
    {
        $this->icu = ($icu ?? true) && class_exists(Transliterator::class);
        $extra ??= (array) config('conversation.gateway.injection.extra_patterns', []);
        $this->extra = array_values(array_filter($extra, fn ($p) => is_string($p) && $p !== '' && @preg_match($p, '') !== false));
    }

    /** @return list<string> ids de los patrones que coinciden (vacío = limpio) */
    public function inspect(string $text, int $depth = 0): array
    {
        if (mb_strlen($text) < self::MIN_LENGTH) {
            return [];
        }
        $memoKey = $depth.'|'.md5($text);
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        if (count($this->memo) >= 5000) {
            $this->memo = [];   // acotado: una instancia de larga vida no crece sin límite
        }

        return $this->memo[$memoKey] = $this->scan($text, $depth);
    }

    /** @return list<string> */
    private function scan(string $text, int $depth): array
    {
        $found = [];
        if (mb_strlen($text) > self::MAX_SCAN_CHARS) {
            $found[] = 'oversized_text';
            $text = mb_substr($text, 0, self::MAX_SCAN_CHARS);
        }
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{E0000}-\x{E007F}]|[\x{200B}\x{200C}\x{2060}\x{FEFF}]{2,}/u', $text) === 1) {
            $found[] = 'hidden_characters';
        }

        foreach ($this->variants($text) as $kind => $variant) {
            foreach (self::PATTERNS as $id => $regex) {
                if (preg_match($regex, $kind === 'base' && $id === 'role_marker' ? $variant['lines'] : $variant['flat']) === 1) {
                    $found[] = $id;
                }
            }
            foreach ($this->extra as $i => $regex) {
                if (preg_match($regex, $variant['flat']) === 1) {
                    $found[] = 'custom_'.($i + 1);
                }
            }
        }
        $base = $this->variants($text)['base']['flat'] ?? '';
        if ($this->imperativeWithTarget($base)) {
            $found[] = 'imperative_request';
        }
        if (preg_match('/\b(?:tu|tus|your)\W+(?:\w+\W+){0,1}(?:instrucciones|reglas|contexto|prompt|configuracion|memoria|entrenamiento|instructions|rules|context|settings|memory|training|restricciones|restrictions|politicas|policies|guidelines|directrices)\b/', $base) === 1 && preg_match('/\b(?:ignor\w*|olvid\w*|forget\w*|repite\w*|repeat|concatena\w*|imprime|print|muestra\w*|show|revela\w*|reveal|prioridad|priority|sobre|over|traduce\w*|translate|resume|summari[sz]e|copia\w*|copy)\b/', $base) === 1) {
            $found[] = 'addresses_assistant_context';
        }
        if (preg_match('/[a-z0-9+\/]{80,}={0,2}/', strtolower((string) preg_replace('/\s+/', ' ', $text))) === 1) {
            $found[] = 'encoded_payload';
        }
        if ($depth < self::MAX_DECODE_DEPTH && $this->decodedSegmentsFlagged($text, $depth)) {
            $found[] = 'encoded_instruction';
        }

        return array_values(array_unique($found));
    }

    /**
     * Recorre un resultado y retiene los valores sospechosos. Los campos tipo nombre que NO se retienen se saneán estructuralmente (marcado fuera, espacios colapsados, longitud
     * acotada). Devuelve el resultado limpio y los hallazgos SIN el texto retenido: solo la ruta del campo (con claves de código) y los ids de los patrones.
     *
     * @param  array<mixed>  $payload
     * @return array{payload: array<mixed>, findings: list<array{path: string, patterns: list<string>}>}
     */
    public function quarantine(array $payload, string $root = 'data'): array
    {
        $findings = [];
        $clean = $this->walk($payload, $root, $findings, null);

        return ['payload' => $clean, 'findings' => $findings];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array{path: string, patterns: list<string>}>  $findings
     * @return array<mixed>
     */
    private function walk(array $node, string $path, array &$findings, ?string $parentKey): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $segment = is_int($key) ? (string) $key : (preg_match('/^[A-Za-z0-9_]{1,40}$/', $key) === 1 ? $key : '*');
            $childPath = $path.'.'.$segment;
            $nameKey = is_string($key) ? $key : $parentKey;
            if (is_string($key)) {
                $keyPatterns = $this->inspect($key);
                if ($keyPatterns !== []) {
                    // Una CLAVE sospechosa (p. ej. un mapa JSON importado) se renombra y su valor se retiene también.
                    $findings[] = ['path' => $path.'.*', 'patterns' => $keyPatterns];
                    $out['[clave retenida '.(count($out) + 1).']'] = self::PLACEHOLDER;

                    continue;
                }
            }
            if (is_string($value)) {
                $patterns = $this->inspect($value);
                if ($patterns !== []) {
                    $findings[] = ['path' => $childPath, 'patterns' => $patterns];
                    $out[$key] = self::PLACEHOLDER;
                } else {
                    $out[$key] = $nameKey !== null && in_array(strtolower($nameKey), self::NAME_KEYS, true) ? self::sanitizeName($value) : $value;
                }
            } elseif (is_array($value)) {
                $out[$key] = $this->walk($value, $childPath, $findings, is_string($key) ? $key : $parentKey);
            } elseif ($value instanceof \stdClass) {
                $out[$key] = (object) $this->walk((array) $value, $childPath, $findings, is_string($key) ? $key : $parentKey);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Saneado ESTRUCTURAL de un campo tipo nombre: sin comentarios ni etiquetas de marcado, enlaces Markdown reducidos a su texto, sin caracteres de control ni de formato,
     * espacios colapsados y longitud acotada. No depende de ningún patrón.
     */
    public static function sanitizeName(string $value): string
    {
        $value = preg_replace('~<!--.*?-->~su', ' ', $value) ?? $value;
        $value = preg_replace('~</?[a-zA-Z][^>]{0,200}>~u', ' ', $value) ?? $value;
        $value = preg_replace('~!?\[([^\]]{0,200})\]\([^)]{0,500}\)~u', '$1', $value) ?? $value;
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > self::NAME_MAX_CHARS ? rtrim(mb_substr($value, 0, self::NAME_MAX_CHARS - 1)).'…' : $value;
    }

    /**
     * Resumen auditable de los hallazgos: cuántos campos, cuántas veces cada patrón y las primeras rutas.
     *
     * @param  list<array{path: string, patterns: list<string>}>  $findings
     * @return array{flagged_fields: int, patterns: array<string, int>, paths: list<string>}
     */
    public static function summarize(array $findings): array
    {
        $patterns = [];
        foreach ($findings as $f) {
            foreach ($f['patterns'] as $id) {
                $patterns[$id] = ($patterns[$id] ?? 0) + 1;
            }
        }
        ksort($patterns);

        return ['flagged_fields' => count($findings), 'patterns' => $patterns, 'paths' => array_slice(array_values(array_unique(array_column($findings, 'path'))), 0, 10)];
    }

    // ── variantes de comparación ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Texto normalizado y sus variantes para las ofuscaciones comunes. Cada variante: `lines` (conserva saltos de línea) y `flat` (una sola línea).
     *
     * @return array<string, array{lines: string, flat: string}>
     */
    private function variants(string $text): array
    {
        $raw = $this->normalize($text);                                          // sin colapsar los espacios: los huecos entre palabras de un texto «espaciado» importan
        $lines = trim((string) preg_replace('/[ \t]+/u', ' ', $raw));
        $flat = (string) preg_replace('/\s+/u', ' ', $lines);
        $variants = ['base' => ['lines' => $lines, 'flat' => $flat]];

        // Letras separadas por puntos, guiones o asteriscos («i.g.n.o.r.e», «I-g-n-o-r-e»): se quita el separador entre caracteres sueltos.
        if (preg_match('/(?:(?<![a-z0-9])[a-z0-9][.\-_*·]){4,}/', $flat) === 1) {
            $variants['dotted'] = ['lines' => $lines, 'flat' => (string) preg_replace('/(?<![a-z0-9])([a-z0-9])[.\-_*·](?=[a-z0-9](?![a-z0-9]))/', '$1', $flat)];
        }
        // Letras separadas por espacios y palabras por huecos de 2 o más («i g n o r e   a l l»).
        $oneLine = (string) preg_replace('/\s*\n\s*/u', '  ', $raw);
        if (preg_match('/(?:(?<![a-z0-9]) ?[a-z0-9] ){5,}/', ' '.$oneLine.' ') === 1) {
            $gap = (string) preg_replace('/ {2,}/', "\x01", $oneLine);
            $gap = (string) preg_replace('/(?<![a-z0-9])([a-z0-9]) (?=[a-z0-9](?![a-z0-9]))/', '$1', $gap);
            $variants['spaced'] = ['lines' => $lines, 'flat' => str_replace("\x01", ' ', $gap)];
        }
        // Leet («1gn0r3 4ll pr3v10us»).
        if (preg_match('/[a-z][013457@$][a-z]/', $flat) === 1) {
            $variants['leet'] = ['lines' => $lines, 'flat' => strtr($flat, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's'])];
        }
        // Errores de dedo en las palabras clave.
        $fuzzy = $this->fuzzy($flat);
        if ($fuzzy !== $flat) {
            $variants['fuzzy'] = ['lines' => $lines, 'flat' => $fuzzy];
        }
        if (mb_strlen($flat) >= 16) {
            // Invertido y ROT13.
            $variants['reversed'] = ['lines' => $lines, 'flat' => implode('', array_reverse(mb_str_split($flat)))];
            $variants['rot13'] = ['lines' => $lines, 'flat' => str_rot13($flat)];
        }
        // Entidades HTML, %XX y \uXXXX.
        if (preg_match('/&#?\w+;|%[0-9a-f]{2}|\\\\u[0-9a-f]{4}|\\\\x[0-9a-f]{2}/i', $text) === 1) {
            $decoded = $this->decodeEscapes($text);
            if ($decoded !== $text) {
                $n = trim((string) preg_replace('/[ \t]+/u', ' ', $this->normalize($decoded)));
                $variants['decoded'] = ['lines' => $n, 'flat' => (string) preg_replace('/\s+/u', ' ', $n)];
            }
        }

        return $variants;
    }

    /**
     * Una FRASE de orden dirigida a un asistente: empieza (tras un preámbulo opcional) con un verbo de orden, tiene al menos 5 palabras y menciona el dominio del asistente
     * (datos, usuarios, permisos, decisiones, credenciales…). Los nombres de producto o de empresa rara vez cumplen las tres condiciones a la vez.
     */
    private function imperativeWithTarget(string $flat): bool
    {
        foreach (preg_split('/[.!?;:\n]+/u', $flat) ?: [] as $sentence) {
            $s = trim($sentence);
            for ($i = 0; $i < 3; $i++) {
                $before = $s;
                foreach (self::FILLERS as $filler) {
                    if (str_starts_with($s.' ', $filler.' ') || str_starts_with($s.',', $filler.',')) {
                        $s = ltrim(substr($s, strlen($filler)), ' ,');
                    }
                }
                if ($s === $before) {
                    break;
                }
            }
            $words = preg_split('/\W+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) >= 5 && in_array($words[0], self::IMPERATIVES, true) && array_intersect(array_slice($words, 1), self::TARGETS) !== []) {
                return true;
            }
        }

        return false;
    }

    private function decodeEscapes(string $text): string
    {
        $text = (string) preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '', $text);
        $text = (string) preg_replace_callback('/\\\\x([0-9a-f]{2})/i', fn (array $m): string => chr((int) hexdec($m[1])), $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return rawurldecode($text);
    }

    /** Corrige palabras con 1–2 errores respecto de una palabra clave («ignnora», «instrucsiones»). */
    private function fuzzy(string $flat): string
    {
        return (string) preg_replace_callback('/\b[a-z]{5,14}\b/', function (array $m): string {
            $w = $m[0];
            foreach (self::FUZZY_KEYWORDS as $kw) {
                if ($w === $kw) {
                    return $w;
                }
                if (abs(strlen($w) - strlen($kw)) <= 2 && levenshtein($w, $kw) <= (strlen($kw) >= 9 ? 2 : 1)) {
                    return $kw;
                }
            }

            return $w;
        }, $flat);
    }

    /** ¿Algún segmento Base64 o hexadecimal del texto decodifica a algo que a su vez es una instrucción? */
    private function decodedSegmentsFlagged(string $text, int $depth): bool
    {
        if (preg_match_all('/[A-Za-z0-9+\/_-]{24,}={0,2}/', $text, $b64) > 0) {
            foreach (array_slice($b64[0], 0, 6) as $segment) {
                $bin = base64_decode(strtr($segment, '-_', '+/'), true);
                if ($bin !== false && self::isMostlyText($bin) && $this->inspect($bin, $depth + 1) !== []) {
                    return true;
                }
            }
        }
        if (preg_match_all('/(?:[0-9a-fA-F]{2}){12,}/', $text, $hex) > 0) {
            foreach (array_slice($hex[0], 0, 6) as $segment) {
                $bin = hex2bin($segment);
                if ($bin !== false && self::isMostlyText($bin) && $this->inspect($bin, $depth + 1) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isMostlyText(string $bin): bool
    {
        if ($bin === '' || ! mb_check_encoding($bin, 'UTF-8')) {
            return false;
        }
        $printable = preg_match_all('/[\p{L}\p{N}\p{P}\p{Zs}]/u', $bin);

        return $printable / max(1, mb_strlen($bin)) >= 0.85;
    }

    /**
     * Forma comparable del texto: sin caracteres de formato ocultos, NFKC, transliteración a ASCII (ICU si existe; si no, el mapa de confusables), minúsculas, sin tildes y
     * espacios colapsados. Conserva los saltos de línea (los marcadores de rol van al inicio de línea) y une las palabras partidas con guion al final de línea.
     */
    private function normalize(string $text): string
    {
        $text = preg_replace('/\r\n?/u', "\n", $text) ?? $text;
        $text = preg_replace('/\p{Cf}+/u', '', $text) ?? $text;               // caracteres de formato (ancho cero, bidi, etiquetas): fuera, para que no partan una palabra
        $text = preg_replace('/[^\P{Cc}\n]+/u', ' ', $text) ?? $text;       // otros controles (tabulación…) → espacio; el salto de línea se conserva
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }
        $text = strtr($text, self::CONFUSABLES);
        if ($this->icu && preg_match('/[^\x00-\x7F]/', $text) === 1) {
            $t = Transliterator::create('Any-Latin; Latin-ASCII');
            $text = ($t !== null ? $t->transliterate($text) : false) ?: $text;
        }
        $text = strtr(mb_strtolower($text), self::CONFUSABLES);   // las MAYÚSCULAS confusables ya se mapearon antes; aquí, las minúsculas que quedaron
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }
        $text = preg_replace('/-\s*\n\s*/u', '', $text) ?? $text;              // «instruc-\nciones» → «instrucciones»

        return trim($text);
    }
}
