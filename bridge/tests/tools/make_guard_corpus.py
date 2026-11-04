"""Regenera bridge/tests/fixtures/sql_guard_cases.json con el guardia PHP (backend/app/Services/Connectors/Sql/SqlQueryGuard.php).

Uso (desde la raíz del repositorio):  python3 bridge/tests/tools/make_guard_corpus.py
Requiere `php` y backend/vendor. Los veredictos de los casos `origin=php` los fija el guardia PHP; los `python_only` (dialecto sqlite) los fija este archivo.
Si cambia una regla de SqlQueryGuard: regenere, ejecute las pruebas de ambos lados y actualice bridge/src/predictily_bridge/sqlguard.py.
"""
import json, subprocess, sys, tempfile
from pathlib import Path
ROOT = Path(__file__).resolve().parents[3]
cases = []
def c(name, dialect, sql, **kw):
    cases.append({"name": name, "dialect": dialect, "sql": sql, **kw})

# --- aceptadas (de SqlQueryGuardTest del backend) ---
c('select simple','pgsql','SELECT a, b FROM t WHERE x >= :since')
c('punto y coma final','pgsql','SELECT 1 FROM t;')
c('varios punto y coma finales y comentario','pgsql',"SELECT 1 FROM t ; ; -- fin\n")
c('cte','pgsql','WITH x AS (SELECT id FROM t) SELECT * FROM x')
c('cadena con palabras peligrosas','pgsql',"SELECT 'DROP TABLE t; --' AS nota, \"update\" FROM t")
c('identificador reservado entrecomillado','pgsql','SELECT "delete", "set" FROM "do"')
c('comentario con palabras peligrosas','pgsql',"SELECT 1 /* DELETE FROM t; */ FROM t -- UPDATE x\n")
c('replace como función','pgsql',"SELECT replace(a, 'x', 'y') FROM t")
c('cast de postgres','pgsql','SELECT a::date FROM t WHERE b::int > 3')
c('comentario anidado de postgres','pgsql','/* x /* anidado */ drop table t */ SELECT 1')
c('mysql con backticks','mysql','SELECT `update`, `set` FROM `t` WHERE f >= :since')
c('mysql con comentario #','mysql',"SELECT 1 # nota; DROP TABLE t\n FROM t")
c('sql server con corchetes y offset','sqlsrv','SELECT [update], b FROM [dbo].[t] ORDER BY 1 OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY')
c('palabra que contiene una reservada','pgsql','SELECT created_at, updated_at, deleted_by, settings FROM t')
# --- más aceptadas ---
c('porcentaje en like','pgsql',"SELECT * FROM t WHERE n LIKE '%x%' AND m % 2 = 0")
c('unicode en identificadores y cadenas','pgsql',"SELECT año, \"Descripción\" FROM tabla_ñ WHERE nombre = 'Pérez'")
c('números y decimales','pgsql','SELECT 1.5, .5, 3e10, 2.5E-3 FROM t')
c('dolar dentro de un identificador','pgsql','SELECT a$b FROM t')
c('doble comilla escapada en cadena','pgsql',"SELECT 'it''s' FROM t")
c('doble comilla escapada en identificador','pgsql','SELECT "a""b" FROM t')
c('parametro since repetido','pgsql','SELECT * FROM t WHERE a >= :since AND b >= :since')
c('since dentro de cadena no es parametro','pgsql',"SELECT ':since' AS x FROM t")
c('comentario de linea al final sin salto','pgsql','SELECT 1 -- fin')
c('espacios y saltos de linea','pgsql',"\n\t  SELECT\n  a ,\n  b\nFROM   t\n")
c('join con alias','pgsql','SELECT f.a, l.b FROM f JOIN l ON l.id = f.id LEFT JOIN c c1 ON c1.id = f.cid WHERE f.m >= :since ORDER BY f.id, l.n')
c('order by y limit','pgsql','SELECT a FROM t ORDER BY a LIMIT 10')
c('mysql sin anidar comentario de bloque','mysql','SELECT 1 /* a /* b */ FROM t')
c('sqlserver comentario anidado','sqlsrv','/* a /* b */ c */ SELECT 1 FROM [t]')
c('sqlserver corchete con cierre escapado','sqlsrv','SELECT [a]]b] FROM t')
c('palabra reservada como alias entre comillas','pgsql','SELECT a AS "into" FROM t')
c('funciones comunes','pgsql',"SELECT coalesce(a, 0), upper(b), cast(c AS integer), date_trunc('day', d) FROM t")
c('sql server top','sqlsrv','SELECT TOP (10) a FROM t WITH (NOLOCK)')
c('pg_ dentro de cadena','pgsql',"SELECT 'pg_shadow' FROM t")
c('information_schema dentro de comentario','pgsql','SELECT 1 /* information_schema */ FROM t')
c('columna llamada sys sin punto','pgsql','SELECT sys FROM t')
c('tabla que contiene una reservada como subcadena','pgsql','SELECT * FROM settings_set')
c('comillas dobles como cadena en mysql','mysql','SELECT "abc" FROM t')
c('with y select mayusculas mixtas','pgsql','WiTh x As (SeLeCt 1) SeLeCt * FrOm x')
# --- rechazadas (de SqlQueryGuardTest) ---
c('vacía','pgsql','   ')
c('update','pgsql','UPDATE t SET a = 1')
c('delete','pgsql','DELETE FROM t')
c('insert','pgsql','INSERT INTO t VALUES (1)')
c('ddl','pgsql','CREATE TABLE x AS SELECT 1')
c('otra sentencia','pgsql','SELECT 1; DROP TABLE t')
c('otra sentencia tras comentario','pgsql',"SELECT 1 -- x\n; DELETE FROM t")
c('cte de escritura','pgsql','WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d')
c('cte con insert','pgsql','WITH i AS (INSERT INTO t VALUES (1) RETURNING *) SELECT * FROM i')
c('select into','pgsql','SELECT * INTO nueva FROM t')
c('select into outfile (mysql)','mysql',"SELECT * FROM t INTO OUTFILE '/tmp/x'")
c('bloqueo de filas','pgsql','SELECT * FROM t FOR UPDATE')
c('bloqueo compartido','pgsql','SELECT * FROM t FOR SHARE')
c('lock in share mode','mysql','SELECT * FROM t LOCK IN SHARE MODE')
c('sql server apilado sin punto y coma','sqlsrv','SELECT 1 DROP TABLE t')
c('sql server exec','sqlsrv',"SELECT 1 EXEC xp_cmdshell 'dir'")
c('sql server waitfor','sqlsrv',"SELECT 1 WAITFOR DELAY '00:00:10'")
c('sql server openrowset','sqlsrv',"SELECT * FROM OPENROWSET('SQLNCLI', 'x', 'SELECT 1')")
c('función de reposo','pgsql','SELECT pg_sleep(30)')
c('sleep mysql','mysql','SELECT SLEEP(30)')
c('lectura de archivo','pgsql',"SELECT pg_read_file('/etc/passwd')")
c('load_file','mysql',"SELECT LOAD_FILE('/etc/passwd')")
c('catálogo de credenciales','pgsql','SELECT * FROM pg_shadow')
c('catálogo entrecomillado','pgsql','SELECT * FROM "pg_shadow"')
c('information_schema','pgsql','SELECT * FROM information_schema.tables')
c('esquema sys','sqlsrv','SELECT * FROM sys.sql_logins')
c('mysql.user','mysql','SELECT * FROM mysql.user')
c('dblink','pgsql',"SELECT * FROM dblink('host=x', 'DELETE FROM t')")
c('nextval','pgsql',"SELECT nextval('s')")
c('set_config','pgsql',"SELECT set_config('x', 'y', false)")
c('escape con barra invertida','pgsql',"SELECT 'a\\' ; DROP TABLE t; --'")
c('dollar quoting','pgsql','SELECT $$ ; DROP TABLE t $$')
c('parámetro posicional','pgsql','SELECT * FROM t WHERE a = $1')
c('parámetro interrogante','mysql','SELECT * FROM t WHERE a = ?')
c('variable de usuario','mysql','SELECT @a := 1')
c('comentario ejecutable mysql','mysql','SELECT /*!50000 1 */ 1')
c('otro parámetro nombrado','pgsql','SELECT * FROM t WHERE a = :otro')
c('cadena sin cerrar','pgsql',"SELECT 'abc")
c('comentario sin cerrar','pgsql','SELECT 1 /* abc')
c('paréntesis que rompe el envoltorio','pgsql','SELECT 1) t UNION ALL SELECT secreto FROM otra WHERE (1=1')
c('tabla temporal sql server','sqlsrv','SELECT * FROM #tmp')
c('demasiado larga','pgsql',None, build={"prefix":"SELECT ","unit":"1,","times":20000,"suffix":"1"})
# --- más rechazadas ---
c('nul','pgsql',"SELECT 1\u0000")
c('solo nul','pgsql',"\u0000")
c('solo punto y coma','pgsql',';')
c('solo comentario','pgsql','-- nada')
c('empieza con paréntesis','pgsql','(SELECT 1)')
c('empieza con valores','pgsql','VALUES (1)')
c('show','pgsql','SHOW server_version')
c('explain','pgsql','EXPLAIN SELECT 1')
c('truncate','pgsql','TRUNCATE t')
c('drop','pgsql','DROP TABLE t')
c('grant','pgsql','GRANT ALL ON t TO u')
c('copy','pgsql',"SELECT 1 FROM t WHERE x IN (COPY)")
c('set dentro de select','pgsql','SELECT a FROM t SET b')
c('merge','pgsql','MERGE INTO t USING s ON 1=1 WHEN MATCHED THEN DELETE')
c('call','pgsql','CALL p()')
c('do','pgsql',"DO $$ BEGIN END $$")
c('begin','pgsql','SELECT 1 BEGIN')
c('commit','pgsql','SELECT 1 COMMIT')
c('vacuum','pgsql','SELECT 1 VACUUM')
c('use mysql','mysql','SELECT 1 USE otra')
c('handler mysql','mysql','SELECT 1 HANDLER t OPEN')
c('declare sqlserver','sqlsrv','SELECT 1 DECLARE @x int')
c('print sqlserver','sqlsrv',"SELECT 1 PRINT 'x'")
c('throw sqlserver','sqlsrv',"SELECT 1 THROW 50000, 'x', 1")
c('xp_ prefijo','sqlsrv','SELECT xp_msver()')
c('sp_ prefijo','sqlsrv','SELECT * FROM sp_who')
c('fn_ prefijo','sqlsrv','SELECT * FROM fn_dblog(NULL, NULL)')
c('sysobjects','sqlsrv','SELECT * FROM sysobjects')
c('sysusers entrecomillado','sqlsrv','SELECT * FROM [sysusers]')
c('master.dbo','sqlsrv','SELECT * FROM master.dbo.sysdatabases')
c('tempdb','sqlsrv','SELECT * FROM tempdb.dbo.t')
c('msdb','sqlsrv','SELECT * FROM msdb.dbo.backupset')
c('performance_schema','mysql','SELECT * FROM performance_schema.threads')
c('model qualifier','sqlsrv','SELECT * FROM model.dbo.t')
c('get_lock','mysql',"SELECT GET_LOCK('x', 10)")
c('benchmark','mysql',"SELECT BENCHMARK(1000000, MD5('a'))")
c('lo_import','pgsql',"SELECT lo_import('/etc/passwd')")
c('query_to_xml','pgsql',"SELECT query_to_xml('select 1', true, false, '')")
c('setval','pgsql',"SELECT setval('s', 1)")
c('replace sin paréntesis','pgsql','REPLACE INTO t VALUES (1)')
c('replace mysql insert','mysql','SELECT replace FROM t')
c('paréntesis sin cerrar','pgsql','SELECT (1 FROM t')
c('paréntesis de más','pgsql','SELECT 1) FROM t')
c('dos puntos solos','pgsql','SELECT a : b FROM t')
c('param con dos puntos y digito','pgsql','SELECT :1')
c('almohadilla fuera de mysql','pgsql','SELECT 1 # c')
c('arroba','pgsql','SELECT @x')
c('corchete fuera de sqlserver','pgsql','SELECT [a] FROM t')
c('backtick fuera de mysql','pgsql','SELECT `a` FROM t')
c('identificador sqlserver sin cerrar','sqlsrv','SELECT [abc FROM t')
c('simbolo euro','pgsql','SELECT 1 € 2')
c('comentario ejecutable M!','mysql','SELECT /*M!50000 1 */ 1')
c('comentario ejecutable en postgres','pgsql','SELECT /*! 1 */ 1')
c('barra invertida en identificador','pgsql','SELECT "a\\b" FROM t')
c('semicolon en medio con select','pgsql','SELECT 1; SELECT 2')
c('select seguido de for no key update','pgsql','SELECT * FROM t FOR NO KEY UPDATE')
c('lock tables','mysql','SELECT 1 LOCK TABLES t READ')
c('shutdown','sqlsrv','SELECT 1 SHUTDOWN')
c('backup','sqlsrv',"SELECT 1 BACKUP DATABASE x TO DISK = 'y'")
c('bulk','sqlsrv',"SELECT 1 BULK INSERT t FROM 'x'")
c('kill','mysql','SELECT 1 KILL 3')
c('load data','mysql',"SELECT 1 LOAD DATA INFILE 'x'")
c('prepare','pgsql','SELECT 1 PREPARE x')
c('notify','pgsql','SELECT 1 NOTIFY x')
c('analyze','pgsql','SELECT 1 ANALYZE')
c('rename','pgsql','SELECT 1 RENAME')
c('alter','pgsql','SELECT 1 ALTER')
c('create temp','pgsql','SELECT 1 CREATE')
c('since sin valor no aplica a validate pero si a render','pgsql','SELECT 1 FROM t WHERE a > :since')

# --- revisión adversarial B-9: secuencias, Service Broker, sugerencias de bloqueo y funciones *_to_xml* ---
c('next value for (sql server)','sqlsrv','SELECT NEXT VALUE FOR dbo.seq_facturas')
c('next value for (mariadb)','mysql','SELECT NEXT VALUE FOR seq_facturas')
c('next value for en minúsculas y con saltos','sqlsrv',"SELECT next\n  value\tfor dbo.s")
c('next value for dentro de una expresión','pgsql','SELECT 1 + NEXT VALUE FOR s FROM t')
c('fetch next sigue permitido','sqlsrv','SELECT a FROM t ORDER BY a OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY')
c('columna llamada next sigue permitida','pgsql','SELECT next, value FROM t')
c('next value sin for sigue permitido','pgsql','SELECT next value FROM t')
c('receive de service broker','sqlsrv','WITH x AS (SELECT 1 AS a) RECEIVE TOP (1) * FROM cola')
c('sugerencia updlock','sqlsrv','SELECT * FROM t WITH (UPDLOCK)')
c('sugerencia holdlock','sqlsrv','SELECT * FROM t WITH (HOLDLOCK)')
c('sugerencia xlock','sqlsrv','SELECT * FROM t WITH (XLOCK, ROWLOCK)')
c('sugerencia tablockx','sqlsrv','SELECT * FROM [t] WITH (TABLOCKX)')
c('sugerencia nolock sigue permitida','sqlsrv','SELECT * FROM t WITH (NOLOCK)')
c('updlock como identificador fuera de sql server','pgsql','SELECT updlock, holdlock, receive FROM t')
c('database_to_xml_and_xmlschema','pgsql',"SELECT database_to_xml_and_xmlschema(true, true, '')")
c('table_to_xml_and_xmlschema','pgsql',"SELECT table_to_xml_and_xmlschema('t', true, true, '')")
c('schema_to_xml_and_xmlschema','pgsql',"SELECT schema_to_xml_and_xmlschema('public', true, true, '')")
c('table_to_xmlschema por patrón','pgsql',"SELECT table_to_xmlschema('t', true, true, '')")
c('cursor_to_xmlschema por patrón','pgsql',"SELECT cursor_to_xmlschema('c', true, true, '')")
c('función propia con _to_xml por patrón','pgsql','SELECT mi_funcion_to_xml(1)')
c('columna con _to_xml sin llamada sigue permitida','pgsql','SELECT fila_to_xml FROM t')

c('lowrite de objetos grandes','pgsql',"SELECT lowrite(1, 'abc')")
c('lo_truncate de objetos grandes','pgsql','SELECT lo_truncate(1, 0)')
c('lo_truncate64 de objetos grandes','pgsql','SELECT lo_truncate64(1, 0)')
c('lo_get sigue permitido (lectura)','pgsql','SELECT lo_get(1)')
c('currval sigue permitido (lectura)','pgsql',"SELECT currval('s')")

# Los casos de sqlite NO tienen paralelo PHP: se marcan para que la prueba de PHP los omita.
sqlite = [
 ('sqlite select simple','SELECT a FROM t WHERE m >= :since ORDER BY a'),
 ('sqlite pragma','PRAGMA table_info(t)'),
 ('sqlite select con pragma','SELECT 1 PRAGMA x'),
 ('sqlite attach','SELECT 1 ATTACH DATABASE \'x\' AS y'),
 ('sqlite sqlite_master','SELECT * FROM sqlite_master'),
 ('sqlite load_extension','SELECT load_extension(\'x\')'),
 ('sqlite readfile','SELECT readfile(\'/etc/passwd\')'),
 ('sqlite corchetes no','SELECT [a] FROM t'),
 ('sqlite comentario no anidado','SELECT 1 /* a /* b */ FROM t'),
 ('sqlite insert','INSERT INTO t VALUES (1)'),
 ('sqlite replace into','REPLACE INTO t VALUES (1)'),
 ('sqlite cte','WITH x AS (SELECT 1 AS a) SELECT a FROM x'),
]
inp = [dict(case) for case in cases]
php = r'''
require "%s/vendor/autoload.php";
use App\Services\Connectors\Sql\SqlQueryGuard; use App\Services\Connectors\Sql\SqlQueryRejected;
$cases = json_decode(file_get_contents($argv[1]), true);
foreach ($cases as &$case) {
    $sql = isset($case["build"]) ? $case["build"]["prefix"].str_repeat($case["build"]["unit"], $case["build"]["times"]).$case["build"]["suffix"] : $case["sql"];
    try {
        $v = SqlQueryGuard::validate($sql, $case["dialect"]);
        $case["accept"] = true;
        $case["uses_since"] = $v->usesSince;
        $case["rendered"] = $v->render("<SINCE>");
        $case["reason"] = null;
    } catch (SqlQueryRejected $e) {
        $case["accept"] = false; $case["reason"] = $e->reason; $case["uses_since"] = null; $case["rendered"] = null;
    }
}
echo json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
''' % str(ROOT / "backend")
import os, tempfile
tmp = Path(tempfile.mkdtemp())
(tmp / 'cases_in.json').write_text(json.dumps(inp, ensure_ascii=False))
(tmp / 'guard.php').write_text('<?php\n' + php)
out = subprocess.run(['php', str(tmp / 'guard.php'), str(tmp / 'cases_in.json')], capture_output=True, text=True)
if out.returncode != 0:
    print(out.stderr); sys.exit(1)
res = json.loads(out.stdout)
for case in res:
    case["origin"] = "php"
# casos sqlite: sin referencia PHP (la prueba de PHP los omite); la expectativa la fija esta lista
exp_sqlite = {
 'sqlite select simple': (True, None), 'sqlite pragma': (False, 'not_select'), 'sqlite select con pragma': (False, 'forbidden_keyword'),
 'sqlite attach': (False, 'forbidden_keyword'), 'sqlite sqlite_master': (False, 'system_object'), 'sqlite load_extension': (False, 'forbidden_function'),
 'sqlite readfile': (False, 'forbidden_function'), 'sqlite corchetes no': (False, 'invalid_character'), 'sqlite comentario no anidado': (True, None),
 'sqlite insert': (False, 'not_select'), 'sqlite replace into': (False, 'not_select'), 'sqlite cte': (True, None),
}
for name, sql in sqlite:
    ok, reason = exp_sqlite[name]
    res.append({"name": name, "dialect": "sqlite", "sql": sql, "accept": ok, "reason": reason, "uses_since": None, "rendered": None, "origin": "python_only"})
dest = ROOT / 'bridge/tests/fixtures/sql_guard_cases.json'
json.dump({"_note": "Generado con el guardia PHP (backend/app/Services/Connectors/Sql/SqlQueryGuard.php) para la paridad con el puerto Python. origin=php: lo comprueban las pruebas de ambos lados; origin=python_only: dialecto sqlite, sin paralelo PHP. Para regenerarlo: python3 bridge/tests/tools/make_guard_corpus.py (ver docs/integrations/BRIDGE_WINDOWS.md §Paridad).", "cases": res}, open(dest,'w'), ensure_ascii=False, indent=1)
print(len(res), "casos;", sum(1 for r in res if r["accept"]), "aceptados")
