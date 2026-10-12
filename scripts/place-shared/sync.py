import datetime,fcntl,json,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parent
with (root/'sync.lock').open('w') as lock:
 try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 except BlockingIOError: raise SystemExit(0)
 state=root/'cursor.json'
 since=json.loads(state.read_text())['since'] if state.exists() else '1970-01-01 00:00:00'
 started=datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(minutes=2)
 exporter=subprocess.Popen(['php83','-d','memory_limit=512M',str(root/'export.php'),since],stdout=subprocess.PIPE)
 imported=subprocess.run(['php83','-d','memory_limit=512M',str(root/'import.php')],stdin=exporter.stdout)
 exporter.stdout.close()
 code=exporter.wait()
 if code or imported.returncode: raise SystemExit(1)
 temp=root/'cursor.tmp'
 temp.write_text(json.dumps({'since':started.strftime('%Y-%m-%d %H:%M:%S')}))
 temp.replace(state)
