from pathlib import Path
import subprocess,time,os
import argparse,tempfile
parser=argparse.ArgumentParser(description='Verifica índices XML en MariaDB desechable, sin configuraciones de instancias.')
parser.add_argument('--output',type=Path)
options=parser.parse_args()
p=(options.output or Path(tempfile.mkdtemp(prefix='table-indexes-result-'))).resolve();p.mkdir(parents=True,exist_ok=True)
source=Path(__file__).resolve().parents[1];name='schema-indexes-test-'+str(os.getpid())
try:
 subprocess.run(['docker','run','-d','--name',name,'--network','none','--memory','800m','--cpus','2','--tmpfs','/var/lib/mysql:rw,size=500m','-e','MARIADB_ROOT_PASSWORD=table-indexes-tests-only','-e','MARIADB_DATABASE=table_indexes_test','mariadb:10.7'],check=True,capture_output=True)
 for attempt in range(70):
  r=subprocess.run(['docker','exec','-e','MYSQL_PWD=table-indexes-tests-only',name,'mariadb-admin','-h127.0.0.1','-uroot','ping','--silent'],capture_output=True)
  if r.returncode==0:break
  time.sleep(.3)
 else:raise RuntimeError('MariaDB no inició')
 cmd=['docker','run','--rm','--name',name+'-php','--network','container:'+name,'-e','TABLE_INDEXES_DISPOSABLE=1','--memory','500m','--cpus','2']
 for folder in ['Core','Plugins','vendor','Test']:
  cmd+=['-v',str(source/folder)+':/app/'+folder+':ro']
 cmd+=['--entrypoint','php','spidercode/erp-hotfix-test:20261110','-d','error_reporting=22527','/app/Test/Core/TableIndexesMariaDb.php']
 r=subprocess.run(cmd,text=True,capture_output=True,timeout=110)
 (p/'mariadb-indexes.stdout').write_text(r.stdout);(p/'mariadb-indexes.stderr').write_text(r.stderr)
 print('exit',r.returncode);print(r.stdout);print(r.stderr[-4000:]);raise SystemExit(r.returncode)
finally:
 subprocess.run(['docker','rm','-fv',name+'-php',name],capture_output=True)
