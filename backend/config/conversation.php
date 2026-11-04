<?php

/*
|--------------------------------------------------------------------------
| Servicio conversacional OPCIONAL (etapa 2 · ADR-V2-12)
|--------------------------------------------------------------------------
| Capa que explica resultados ya calculados mediante un proveedor LLM externo y herramientas de lista blanca
| (app/Services/Conversation). NUNCA calcula cifras y su ausencia no afecta a los cuatro cores: sin clave
| (CONVERSATION_API_KEY) el servicio queda desactivado y POST /api/v1/assistant/messages responde 503 assistant_disabled.
|
| Variables de entorno (sin valores reales en el repositorio):
|   CONVERSATION_ENABLED             true|false  interruptor general (por defecto true; sin clave igual queda desactivado)
|   CONVERSATION_PROVIDER            ollama_cloud (por defecto) | none
|   CONVERSATION_BASE_URL            endpoint de la API de Ollama Cloud (por defecto https://ollama.com)
|   CONVERSATION_API_KEY             clave del proveedor (solo en el .env del servidor; nunca en código, logs ni respuestas)
|   CONVERSATION_MODEL               modelo en la nube a usar
|   CONVERSATION_TIMEOUT_SECONDS     tiempo máximo de CADA llamada al proveedor
|   CONVERSATION_DEADLINE_SECONDS    tiempo máximo de toda la conversación de un mensaje (varias rondas de herramientas)
|   CONVERSATION_MAX_MESSAGE_CHARS   tamaño máximo del mensaje del usuario
|   CONVERSATION_RATE_PER_MINUTE     mensajes por minuto y usuario/empresa
|   CONVERSATION_DAILY_LIMIT         mensajes por día y empresa (0 = sin tope diario)
|   CONVERSATION_WHATSAPP_ENABLED     false por defecto: el canal WhatsApp es solo un CONTRATO (V2.5 · W6-D); sin un proveedor registrado en el código no existe (404)
|
| Intelligence Gateway (V2.5 · W6-D; ADR-V25-17): toda herramienta se ejecuta por IntelligenceGateway (autorización por herramienta, argumentos validados contra su
| esquema, defensa ante inyección y auditoría en gateway_tool_calls). Ver docs/architecture/INTELLIGENCE_GATEWAY.md.
*/

return [
    'enabled' => (bool) env('CONVERSATION_ENABLED', true),

    // ollama_cloud | none. Otros proveedores se incorporan con ConversationManager::extend() sin tocar el orquestador.
    'provider' => (string) env('CONVERSATION_PROVIDER', 'ollama_cloud'),

    'ollama' => [
        'base_url' => rtrim((string) env('CONVERSATION_BASE_URL', 'https://ollama.com'), '/'),
        'api_key' => env('CONVERSATION_API_KEY'),
        // Modelo de la nube de Ollama con soporte de herramientas; verificar el catálogo vigente antes de producción.
        'model' => (string) env('CONVERSATION_MODEL', 'gpt-oss:120b'),
        'timeout_seconds' => (int) env('CONVERSATION_TIMEOUT_SECONDS', 30),
        'connect_timeout_seconds' => (int) env('CONVERSATION_CONNECT_TIMEOUT_SECONDS', 5),
        // Tope de tokens generados por llamada (reduce costo y tiempo; las cifras NO las genera el modelo).
        'max_output_tokens' => (int) env('CONVERSATION_MAX_OUTPUT_TOKENS', 700),
        'temperature' => (float) env('CONVERSATION_TEMPERATURE', 0.1),
    ],

    'limits' => [
        'max_message_chars' => (int) env('CONVERSATION_MAX_MESSAGE_CHARS', 1000),
        'max_history_messages' => 6,
        'max_history_chars' => 1500,
        // Rondas modelo→herramientas por mensaje y llamadas a herramientas por ronda.
        'max_tool_rounds' => 3,
        'max_tool_calls_per_round' => 3,
        // Tamaño máximo (caracteres JSON) del resultado de una herramienta que se entrega al modelo.
        'max_tool_result_chars' => 12000,
        'deadline_seconds' => (int) env('CONVERSATION_DEADLINE_SECONDS', 45),
        'rate_per_minute' => (int) env('CONVERSATION_RATE_PER_MINUTE', 10),
        'daily_limit_per_tenant' => (int) env('CONVERSATION_DAILY_LIMIT', 300),
        // Filas máximas por herramienta que devuelven listas.
        'max_rows_per_tool' => 10,
    ],

    'gateway' => [
        // Catálogo de herramientas por canal ('*' = todas las habilitadas). Un canal que no figura aquí no tiene ninguna herramienta.
        // WhatsApp (contrato, sin proveedor) quedaría limitado a consultas operativas y comerciales, nunca a ROI ni a calidad de modelos.
        'channel_tools' => [
            'web' => '*',
            'api' => '*',
            'whatsapp' => ['dashboard_summary', 'radar_opportunities', 'decisions_summary', 'inventory_recommendations'],
        ],
        // Plazo por herramienta (segundos; 0 = sin plazo): statement_timeout de PostgreSQL + descarte del resultado si la ejecución lo superó.
        'tool_timeout_seconds' => (float) env('CONVERSATION_TOOL_TIMEOUT_SECONDS', 10),
        'injection' => [
            // Expresiones regulares ADICIONALES (PCRE, con delimitadores y modificador u) que se suman a las incorporadas; sobre el texto normalizado (minúsculas, sin tildes).
            'extra_patterns' => [],
        ],
    ],

    'channels' => [
        // Canal WhatsApp: solo contrato (interfaz MessagingChannel, plantillas como BORRADOR y confirmación humana explícita). Sin proveedor no existe (404) y nada se envía.
        'whatsapp' => [
            'enabled' => (bool) env('CONVERSATION_WHATSAPP_ENABLED', false),
            // Un borrador sin confirmar caduca (minutos) y se descarta.
            'draft_ttl_minutes' => 1440,
        ],
    ],

    'privacy' => [
        // Los resultados que se envían al proveedor pasan por PiiMasker; nunca incluyen nombres, correos ni cédula/RUC.
        // Los códigos externos de compradores (external_code) son necesarios para consultar un comprador; desactivar para
        // empresas que no pueden compartirlos con un proveedor externo.
        'customer_tools_enabled' => (bool) env('CONVERSATION_CUSTOMER_TOOLS', true),
    ],
];
