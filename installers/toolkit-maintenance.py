#!/usr/bin/env python3
"""Bound disposable Toolkit files only. Never touches RADIUS, SQL or live config."""
import argparse, datetime, fcntl, json, os, pathlib, re, shutil, stat, time

MiB=1024*1024
def safe_dir(value):
    p=pathlib.Path(value)
    if not p.is_absolute() or str(p) in ('/','/tmp','/var','/var/tmp','/root','/home','/opt','/opt/mk-auth'):raise ValueError('unsafe maintenance directory')
    if p.resolve()!=p or p.is_symlink():raise ValueError('symlink maintenance directory')
    return p

def regular(p):
    return p.is_file() and not p.is_symlink()

def prune_cache(root,now,dry=False,limit=64*MiB,max_files=10000):
    root=safe_dir(root);candidates=[];removed=0
    for folder,pattern in [(root,r'(?:photon-)?[a-f0-9]{64}\.json'),(root/'batch-address',r'[a-f0-9]{40}\.json')]:
        if not folder.is_dir() or folder.is_symlink():continue
        for p in folder.iterdir():
            if regular(p) and re.fullmatch(pattern,p.name):candidates.append((p,p.stat()))
    total=sum(s.st_size for _,s in candidates);remaining=len(candidates)
    for p,s in sorted(candidates,key=lambda x:x[1].st_mtime):
        if now-s.st_mtime>30*86400 or total>limit or remaining>max_files:
            if not dry:p.unlink()
            removed+=s.st_size;total-=s.st_size;remaining-=1
    jobs=root/'batch-jobs'
    if jobs.is_dir() and not jobs.is_symlink():
        for p in jobs.iterdir():
            if not regular(p) or not re.fullmatch(r'[a-f0-9]{32}\.json',p.name) or now-p.stat().st_mtime<7*86400:continue
            lock=jobs/'worker.lock'
            if not regular(lock):continue
            fd=os.open(str(lock),os.O_RDONLY|os.O_NOFOLLOW)
            try:
                try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                except BlockingIOError:continue
                if not regular(p) or p.stat().st_size>16*MiB:continue
                try:job=json.loads(p.read_text())
                except (ValueError,OSError):continue
                if job.get('status')!='completed':continue
                removed+=p.stat().st_size
                if not dry:p.unlink()
            finally:os.close(fd)
    return removed

def tree_size(p):
    total=0
    for base,dirs,files in os.walk(str(p),followlinks=False):
        dirs[:]=[d for d in dirs if not pathlib.Path(base,d).is_symlink()]
        for name in files:
            f=pathlib.Path(base,name)
            if regular(f):total+=f.stat().st_size
    return total

def prune_backups(root,now,dry=False,limit=512*MiB):
    root=safe_dir(root);removed=0
    if not root.is_dir():return 0
    for kind in ('mapa-clientes','geocodificacao'):
        items=[]
        for p in root.iterdir():
            if p.is_symlink() or not p.is_dir() or not re.fullmatch(r'mk-auth-'+kind+r'-\d{8}-\d{6}-v[\d.]+',p.name):continue
            marker=p/'.mkauth-toolkit-backup-v1'
            if not regular(marker) or marker.read_text().strip()!=kind:continue
            items.append((p,marker.stat().st_mtime,tree_size(p)))
        items.sort(key=lambda x:x[1],reverse=True);total=sum(x[2] for x in items);remaining=len(items)
        # Preserve rollback snapshots even after repeated failed installations.
        completed=[x[0] for x in items if regular(x[0]/'.mkauth-toolkit-backup-complete')]
        protected=set(x[0] for x in items[:2])|set(completed[:2])
        for p,mtime,size in reversed(items):
            if p in protected:continue
            if not regular(p/'.mkauth-toolkit-backup-complete') and now-mtime<86400:continue
            if remaining>7 or now-mtime>30*86400 or total>limit:
                if p.parent!=root or p.resolve().parent!=root:raise ValueError('backup escaped root')
                if not dry:shutil.rmtree(str(p))
                removed+=size;total-=size;remaining-=1
    return removed

def prune_temporary(root,now,dry=False):
    root=safe_dir(root);removed=0
    if not root.is_dir():return 0
    for p in root.iterdir():
        if regular(p) and p.name.startswith('.telegram-') and now-p.stat().st_mtime>86400:
            removed+=p.stat().st_size
            if not dry:p.unlink()
    return removed

def bounded_log(path,message):
    p=pathlib.Path(path)
    if p.is_symlink():raise ValueError('symlink maintenance log')
    with open(str(p),'a+b') as f:
        fcntl.flock(f,fcntl.LOCK_EX)
        f.seek(0,2)
        if f.tell()>256*1024:
            f.seek(-128*1024,2);tail=f.read();f.seek(0);f.truncate();f.write(tail.split(b'\n',1)[-1])
        f.write((message+'\n').encode());f.flush()
    os.chmod(str(p),0o600)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--config',required=True);parser.add_argument('--dry-run',action='store_true');args=parser.parse_args()
    config_path=pathlib.Path(args.config)
    if config_path.is_symlink():raise ValueError('symlink maintenance configuration')
    cfg=json.loads(config_path.read_text());now=time.time();freed=0
    # Lock lives outside addon/runtime so updating either cannot duplicate cleanup.
    lock=pathlib.Path(cfg['lock']);lock.parent.mkdir(parents=True,exist_ok=True)
    fd=os.open(str(lock),os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:return
        for root in cfg.get('cache_dirs',[]):freed+=prune_cache(root,now,args.dry_run)
        for root in cfg.get('backup_roots',[]):freed+=prune_backups(root,now,args.dry_run)
        for root in cfg.get('telegram_dirs',[]):freed+=prune_temporary(root,now,args.dry_run)
        usage=shutil.disk_usage(cfg['disk_path']);low=usage.free<1024*MiB or usage.free/usage.total<0.05
        line='{} toolkit-maintenance dry={} freed_bytes={} free_bytes={} low_space={}'.format(datetime.datetime.now().isoformat(),args.dry_run,freed,usage.free,low)
        if not args.dry_run:bounded_log(cfg['log'],line)
        print(line)
    finally:os.close(fd)

if __name__=='__main__':
    try:main()
    except Exception as error:
        # Cron discards stdout/stderr; keep one bounded diagnostic without secrets.
        try:
            arg=pathlib.Path(os.sys.argv[os.sys.argv.index('--config')+1])
            if not arg.is_symlink():
                cfg=json.loads(arg.read_text());bounded_log(cfg['log'],datetime.datetime.now().isoformat()+' maintenance_error='+type(error).__name__)
        except Exception:pass
        print('Toolkit maintenance failed; inspect configuration and permissions.',file=os.sys.stderr)
        raise SystemExit(1)
