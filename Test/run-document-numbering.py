"""Numeración y rendimiento en MariaDB desechable, sin configuración ni datos reales."""
from pathlib import Path
import argparse
import json
import os
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('mode', nargs='?', default='all', choices=[
    'all', 'smoke', 'equivalence', 'invariants', 'foreign-keys', 'cli', 'concurrency', 'concurrency-no-gaps',
    'benchmark', 'benchmark-no-gaps'])
parser.add_argument('--baseline', action='store_true', help='Usar los archivos originales indicados.')
parser.add_argument('--baseline-dir', type=Path, help='Copia anterior de Core con las mismas modificaciones locales.')
parser.add_argument('--output', type=Path, help='Carpeta para resultados; por defecto crea una carpeta temporal.')
parser.add_argument('--php-image', default=os.environ.get('NUMBERING_PHP_IMAGE', 'main_spidercodeapp-php:latest'),
    help='Imagen PHP con mysqli y PDO MySQL; se usa PHP 7.4 para validar producción.')
args = parser.parse_args()
if args.baseline and (not args.baseline_dir or args.mode not in ['equivalence','benchmark','benchmark-no-gaps']):
    parser.error('--baseline requiere --baseline-dir y un modo de comparación.')
source = Path(__file__).resolve().parents[1]
output = (args.output or Path(tempfile.mkdtemp(prefix='document-numbering-'))).resolve()
output.mkdir(parents=True, exist_ok=True)
baseline = args.baseline_dir.resolve() if args.baseline_dir else output / 'empty-baseline'
if args.baseline and not (baseline/'Core/Lib/BusinessDocumentCode.php').is_file():
    parser.error('Falta BusinessDocumentCode.php en la copia original.')
baseline.mkdir(parents=True, exist_ok=True)
name = 'document-numbering-test-' + str(os.getpid())
modes = ['equivalence','invariants','cli','concurrency','concurrency-no-gaps','benchmark','benchmark-no-gaps','foreign-keys'] if args.mode=='all' else [args.mode]
php_containers = []
results = []

subprocess.run(['docker','run','-d','--name',name,'--network','none','--memory','1g','--cpus','2',
    '--tmpfs','/var/lib/mysql:rw,size=700m','-e','MARIADB_ROOT_PASSWORD=numbering-tests-only',
    '-e','MARIADB_DATABASE=numbering_test','mariadb:10.7'],check=True,capture_output=True)
try:
    for attempt in range(60):
        ready = subprocess.run(['docker','exec','-e','MYSQL_PWD=numbering-tests-only',name,
            'mariadb-admin','--protocol=tcp','-h127.0.0.1','-uroot','ping','--silent'],capture_output=True)
        if ready.returncode==0:
            break
        time.sleep(0.5)
    else:
        raise RuntimeError('MariaDB desechable no inició.')
    for mode in modes:
        php_name = name + '-' + mode
        php_containers.append(php_name)
        command = ['docker','run','--rm','--name',php_name,'--network','container:'+name,
            '--memory','700m','--cpus','2','-e','NUMBERING_DISPOSABLE_DB=1',
            '-e','NUMBERING_BASELINE='+('1' if args.baseline else '0')]
        # Incluye los plugins ignorados por Git; no monta config.php ni MyFiles.
        for directory in ['Core','Plugins','Dinamic','vendor','Test','Tools']:
            command.extend(['-v',str(source/directory)+':/app/'+directory+':ro'])
        command.extend(['-v',str(baseline)+':/baseline:ro','--entrypoint','php',args.php_image,
            '/app/Test/Core/DocumentNumberingMariaDb.php',mode])
        result = subprocess.run(command,text=True,capture_output=True,timeout=180)
        label = mode+('-baseline' if args.baseline else '')
        (output/(label+'.stdout.log')).write_text(result.stdout)
        (output/(label+'.stderr.log')).write_text(result.stderr)
        if result.returncode:
            raise RuntimeError(label+' falló: '+result.stderr[-3000:])
        data = json.loads(result.stdout)
        if not data.get('checks'):
            raise RuntimeError(label+' no ejecutó comprobaciones.')
        results.append({'mode':label,'result':data})
        print(json.dumps({'mode':label,'passed':True,'checks':data['checks']},ensure_ascii=False),flush=True)
        (output/'runner-summary.json').write_text(json.dumps(results,ensure_ascii=False,indent=2)+'\n')
finally:
    for container in php_containers:
        subprocess.run(['docker','rm','-fv',container],capture_output=True)
    subprocess.run(['docker','rm','-fv',name],check=True,capture_output=True)
