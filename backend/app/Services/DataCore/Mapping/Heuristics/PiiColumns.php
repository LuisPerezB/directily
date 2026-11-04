<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

/**
 * Detecta por NOMBRE las columnas que probablemente contienen datos personales. Es una política conservadora (no hace falta leer ni un valor):
 * - Una columna con nombre de persona, correo, teléfono, dirección, fecha de nacimiento u otro dato sensible NUNCA se mapea ni se muestrea.
 * - Un identificador nacional (cédula, RUC, pasaporte) solo se admite como código de cliente de las ventas o RUC del proveedor: el importador
 *   guarda las cédulas y los RUC como huella (HMAC), nunca en claro. Tampoco se muestrea.
 */
final class PiiColumns
{
    public const PERSON_NAME = 'person_name';

    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const ADDRESS = 'address';

    public const NATIONAL_ID = 'national_id';

    public const BIRTHDATE = 'birthdate';

    public const SENSITIVE = 'sensitive';

    private const PERSON_CONCEPTS = ['cliente', 'comprador', 'customer', 'contacto', 'persona', 'titular', 'empleado', 'vendedor', 'usuario', 'propietario', 'representante', 'paciente'];

    private const NAME_TOKENS = ['nombre', 'razon', 'apellido', 'social', 'completo'];

    /**
     * @param  list<string>  $tokens  tokens del nombre de la columna (NameTokens::of)
     * @param  array<string, float>  $roles  roles de la tabla (TableRoles)
     * @return ?string tipo de dato personal o null
     */
    public static function classify(array $tokens, array $roles): ?string
    {
        $has = fn (array $set): bool => array_intersect($set, $tokens) !== [];
        if ($has(['correo', 'email', 'mail'])) {
            return self::EMAIL;
        }
        if ($has(['telefono', 'celular', 'movil', 'fono', 'whatsapp', 'phone', 'tel', 'tlf', 'tlfno', 'celu', 'cell', 'mobile', 'telefonico'])) {
            return self::PHONE;
        }
        if ($has(['nacimiento', 'nac', 'cumpleano', 'cumple', 'birth', 'birthdate', 'birthday', 'natalicio', 'edad', 'age'])) {
            return self::BIRTHDATE;
        }
        if ($has(['genero', 'sexo', 'religion', 'etnia', 'discapacidad', 'salud', 'diagnostico', 'afiliacion', 'tarjeta', 'card', 'cvv', 'iban', 'gps', 'latitud', 'longitud', 'password', 'contrasena',
            'token', 'secret', 'secreto', 'huella', 'biometrico'])) {
            return self::SENSITIVE;
        }
        if ($has(['cedula', 'ruc', 'identificacion', 'identidad', 'pasaporte', 'dni', 'nit', 'rfc', 'curp'])) {
            return self::NATIONAL_ID;
        }
        if ($has(['direccion', 'domicilio'])) {
            // La dirección de una sucursal, bodega o proveedor es dato de la empresa; la de una persona, no.
            return ($roles['branch'] ?? 0.0) > 0 || ($roles['warehouse'] ?? 0.0) > 0 || ($roles['supplier'] ?? 0.0) > 0 ? null : self::ADDRESS;
        }
        $personal = ($roles['customer'] ?? 0.0) > 0 || $has(self::PERSON_CONCEPTS);
        if ($has(self::NAME_TOKENS) && $personal) {
            return self::PERSON_NAME;
        }
        // Texto libre sobre una persona (observaciones, comentarios, notas): puede contener cualquier dato personal.
        if ($has(['observacion', 'comentario', 'nota']) && $personal) {
            return self::SENSITIVE;
        }

        return null;
    }

    /**
     * ¿Puede PROPONERLA el Auto Mapper? Solo el RUC de un proveedor (persona jurídica). Una identificación nacional de una persona (cédula, RUC de cliente) no se propone nunca como
     * código de cliente: aunque el importador la guarda solo como huella (HMAC), la decisión de usarla es de una persona (`mappable()` con corrección manual).
     */
    public static function proposable(?string $kind, string $entity, string $field): bool
    {
        return $kind === null || ($kind === self::NATIONAL_ID && $entity === 'suppliers' && $field === 'tax_id');
    }

    /** ¿Se puede mapear una columna de este tipo al campo (corrección manual)? Solo identificadores nacionales, y solo donde el importador los hashea. */
    public static function mappable(?string $kind, string $entity, string $field): bool
    {
        if ($kind === null) {
            return true;
        }

        return $kind === self::NATIONAL_ID && (($entity === 'sales' && $field === 'customer_code') || ($entity === 'suppliers' && $field === 'tax_id'));
    }

    /** Un tipo de dato personal nunca se muestrea, ni siquiera el identificador nacional. */
    public static function neverSample(?string $kind): bool
    {
        return $kind !== null;
    }
}
