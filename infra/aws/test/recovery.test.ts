import { spawnSync } from 'child_process';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';

/**
 * Prueba de RECUPERACIÓN LOCAL (respaldar → restaurar en una base vacía → comparar conteos) con infra/scripts/recovery-check.sh.
 * Necesita PostgreSQL y una base de pruebas con el esquema y datos cargados; sin la variable RECOVERY_CHECK_DB se OMITE con
 * un mensaje explícito (no pasa en silencio). Ejemplo:
 *   RECOVERY_CHECK_DB=predictily_test_w4c DB_PASSWORD=<clave local> PATH=/opt/homebrew/opt/postgresql@15/bin:$PATH npm test -- recovery
 * Nunca use la base «predictily».
 */
const SOURCE = process.env.RECOVERY_CHECK_DB;
const SCRIPT = path.resolve(__dirname, '../../scripts/recovery-check.sh');
const BACKUP = path.resolve(__dirname, '../../scripts/backup.sh');

if (!SOURCE) {
  // eslint-disable-next-line no-console
  console.warn('[OMITIDA] recovery.test.ts: defina RECOVERY_CHECK_DB=<base de pruebas con datos> para ejecutar la prueba de restauración contra PostgreSQL.');
}
const run = SOURCE ? describe : describe.skip;

const baseEnv = (extra: Record<string, string> = {}): NodeJS.ProcessEnv => ({
  ...process.env,
  BACKUP_IGNORE_ENV_FILE: 'true',
  BACKUP_MODE: 'native',
  ...extra,
});
const sh = (file: string, args: string[], env: NodeJS.ProcessEnv): { status: number | null; out: string } => {
  const r = spawnSync('bash', [file, ...args], { env, encoding: 'utf8' });
  return { status: r.status, out: `${r.stdout}${r.stderr}` };
};

run('recuperación local de PostgreSQL', () => {
  let dir: string;
  let dump: string;

  beforeAll(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'predictily-recovery-'));
    const r = sh(BACKUP, [], baseEnv({ DB_DATABASE: SOURCE!, BACKUP_DIR: dir }));
    expect(r.status).toBe(0);
    dump = fs.readdirSync(dir).filter((f) => f.endsWith('.dump')).map((f) => path.join(dir, f))[0];
  });
  afterAll(() => fs.rmSync(dir, { recursive: true, force: true }));

  it('backup.sh genera el respaldo, su suma SHA-256 y el manifiesto de conteos', () => {
    expect(fs.existsSync(dump)).toBe(true);
    expect(fs.readFileSync(dump).subarray(0, 5).toString()).toBe('PGDMP');
    expect(fs.existsSync(`${dump}.sha256`)).toBe(true);
    const manifest = fs.readFileSync(`${dump}.counts`, 'utf8');
    expect(manifest).toMatch(/^tables=\d+$/m);
    expect(manifest).toMatch(/^schema_md5=[0-9a-f]{32}$/m);
    expect(manifest.match(/^count\|/gm)!.length).toBeGreaterThan(50);
  });

  it('restaura en una base vacía y TODOS los conteos, el esquema, índices, restricciones y secuencias coinciden (salida 0)', () => {
    const r = sh(SCRIPT, ['verify', dump], baseEnv());
    expect(r.out).toMatch(/IDÉNTICO: \d+ tablas, \d+ filas/);
    expect(r.status).toBe(0);
  });

  it('el manifiesto incluye el valor de cada secuencia y una secuencia restaurada con otro valor se detecta (salida 1)', () => {
    const manifest = `${dump}.counts`;
    const original = fs.readFileSync(manifest, 'utf8');
    const seq = original.match(/^seq\|([a-z_0-9]+)\|(\d+)$/m)!;
    expect(seq).not.toBeNull();
    fs.writeFileSync(manifest, original.replace(seq[0], `seq|${seq[1]}|${Number(seq[2]) + 100}`));
    try {
      const r = sh(SCRIPT, ['verify', dump], baseEnv());
      expect(r.status).toBe(1);
      expect(r.out).toContain(`seq|${seq[1]}|`);
    } finally {
      fs.writeFileSync(manifest, original);
    }
  });

  it('detecta una diferencia de filas (manifiesto alterado) y sale con 1', () => {
    const manifest = `${dump}.counts`;
    const original = fs.readFileSync(manifest, 'utf8');
    const first = original.match(/^count\|([a-z_]+)\|(\d+)$/m)!;
    fs.writeFileSync(manifest, original.replace(first[0], `count|${first[1]}|${Number(first[2]) + 1}`));
    try {
      const r = sh(SCRIPT, ['verify', dump], baseEnv());
      expect(r.status).toBe(1);
      expect(r.out).toContain('DIFERENCIAS');
    } finally {
      fs.writeFileSync(manifest, original);
    }
  });

  it('rechaza un respaldo alterado (SHA-256) con salida 3, sin restaurar', () => {
    const bad = path.join(dir, 'alterado.dump');
    const bytes = fs.readFileSync(dump);
    bytes[Math.min(2000, bytes.length - 1)] ^= 0xff;
    fs.writeFileSync(bad, bytes);
    fs.copyFileSync(`${dump}.sha256`, `${bad}.sha256`);
    fs.writeFileSync(`${bad}.sha256`, fs.readFileSync(`${bad}.sha256`, 'utf8').replace(path.basename(dump), path.basename(bad)));
    fs.copyFileSync(`${dump}.counts`, `${bad}.counts`);
    const r = sh(SCRIPT, ['verify', bad], baseEnv());
    expect(r.status).toBe(3);
    expect(r.out).toContain('SHA-256');
  });

  it('cifrado (GPG SIMULADO con un stub: prueba el flujo de archivos, NO la criptografía real): el manifiesto no queda en claro y verify lo descifra', () => {
    const bin = path.join(dir, 'bin');
    fs.mkdirSync(bin);
    // Stub de gpg: `--encrypt` y `--decrypt` se implementan con base64 (solo para ejercitar nombres, borrado del texto en claro y lectura).
    fs.writeFileSync(
      path.join(bin, 'gpg'),
      '#!/usr/bin/env bash\nout=""; mode=""; in=""\nwhile [ $# -gt 0 ]; do case "$1" in --output) out="$2"; shift 2;; --recipient) shift 2;; --encrypt) mode=enc; in="$2"; shift 2;; --decrypt) mode=dec; in="$2"; shift 2;; *) shift;; esac; done\n' +
        'if [ "$mode" = enc ]; then base64 < "$in" > "$out"; else base64 -d < "$in" > "$out"; fi\n',
      { mode: 0o755 },
    );
    const gpgDir = path.join(dir, 'cifrado');
    fs.mkdirSync(gpgDir);
    const env = baseEnv({ PATH: `${bin}:${process.env.PATH}`, DB_DATABASE: SOURCE!, BACKUP_DIR: gpgDir, BACKUP_GPG_RECIPIENT: 'prueba@example.com' });
    expect(sh(BACKUP, [], env).status).toBe(0);
    const files = fs.readdirSync(gpgDir).sort();
    expect(files.filter((f) => f.endsWith('.dump'))).toHaveLength(0); // el volcado en claro se borra
    expect(files.filter((f) => f.endsWith('.counts'))).toHaveLength(0); // el manifiesto en claro también
    const encrypted = files.find((f) => f.endsWith('.dump.gpg'))!;
    expect(files).toContain(`${encrypted}.counts.gpg`);
    const r = sh(SCRIPT, ['verify', path.join(gpgDir, encrypted)], env);
    expect(r.out).toMatch(/IDÉNTICO/);
    expect(r.status).toBe(0);
  });

  it('el .env se lee sin espacios finales ni CR ni comentarios, y las variables del entorno tienen precedencia (backup.sh, restore.sh)', () => {
    const tree = path.join(dir, 'arbol');
    fs.mkdirSync(path.join(tree, 'infra', 'scripts'), { recursive: true });
    for (const f of ['backup.sh', 'restore.sh', 'recovery-check.sh']) fs.copyFileSync(path.resolve(__dirname, '../../scripts', f), path.join(tree, 'infra', 'scripts', f));
    fs.writeFileSync(path.join(tree, '.env'), `DB_DATABASE=${SOURCE}   \r\nDB_USERNAME="${process.env.DB_USERNAME ?? 'predictily'}"  \nBACKUP_RETENTION_DAYS=3 # comentario\nDB_HOST=127.0.0.1\n`);
    const out = path.join(tree, 'copias');
    // Sin DB_DATABASE ni DB_USERNAME en el entorno: manda el .env, recortado.
    const env: NodeJS.ProcessEnv = { ...process.env, BACKUP_MODE: 'native', BACKUP_DIR: out };
    delete env.DB_DATABASE;
    delete env.DB_USERNAME;
    delete env.BACKUP_IGNORE_ENV_FILE;
    const r = sh(path.join(tree, 'infra/scripts/backup.sh'), [], env);
    expect(r.out).toContain(`base=${SOURCE} destino=`);
    expect(r.status).toBe(0);
    expect(fs.readdirSync(out).some((f) => f.startsWith(`${SOURCE}_`) && f.endsWith('.dump'))).toBe(true);
    // Con DB_DATABASE en el entorno, el entorno gana al .env (que apunta a otra base).
    fs.writeFileSync(path.join(tree, '.env'), 'DB_DATABASE=base_que_no_existe\nDB_USERNAME=usuario_que_no_existe\n');
    const r2 = sh(path.join(tree, 'infra/scripts/backup.sh'), [], { ...env, DB_DATABASE: SOURCE!, DB_USERNAME: process.env.DB_USERNAME ?? 'predictily', BACKUP_DIR: path.join(tree, 'copias2') });
    expect(r2.out).toContain(`base=${SOURCE} destino=`);
    expect(r2.status).toBe(0);
    // restore.sh --verify usa la misma regla (antes el .env pisaba al entorno).
    const r3 = sh(path.join(tree, 'infra/scripts/restore.sh'), ['--verify', path.join(dir, path.basename(dump))], { ...env, DB_USERNAME: process.env.DB_USERNAME ?? 'predictily' });
    expect(r3.out).toContain('Prueba de restauración completada');
    expect(r3.status).toBe(0);
  });

  it('se niega a trabajar con la base «predictily» (salida 2) y sin argumentos muestra el uso', () => {
    expect(sh(SCRIPT, ['cycle', 'predictily'], baseEnv()).status).toBe(2);
    expect(sh(SCRIPT, [], baseEnv()).status).toBe(2);
  });

  it('no deja bases temporales ni archivos tras el ciclo completo', () => {
    const r = sh(SCRIPT, ['cycle', SOURCE!], baseEnv());
    expect(r.status).toBe(0);
    const list = spawnSync('psql', ['-h', process.env.DB_HOST ?? '127.0.0.1', '-p', process.env.DB_PORT ?? '5432', '-U', process.env.DB_USERNAME ?? 'predictily', '-d', 'postgres', '-Atc', "select count(*) from pg_database where datname like 'predictily_recovery_check_%'"], {
      env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD ?? '' },
      encoding: 'utf8',
    });
    expect(list.stdout.trim()).toBe('0');
  });
});
