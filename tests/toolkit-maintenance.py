import fcntl,importlib.util,json,os,pathlib,tempfile,time
source=pathlib.Path(__file__).resolve().parents[1]/'installers/toolkit-maintenance.py'
spec=importlib.util.spec_from_file_location('maintenance',source);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=time.time()
def write(p,text='fixture',days=0):
    p.parent.mkdir(parents=True,exist_ok=True);p.write_text(text);os.utime(str(p),(now-days*86400,now-days*86400));return p
with tempfile.TemporaryDirectory() as tmp:
    base=pathlib.Path(tmp);cache=base/'geo';cache.mkdir();outside=write(base/'outside.json');write(cache/'config.php');write(cache/'unknown.json')
    old=write(cache/('a'*64+'.json'),days=31);fresh=write(cache/('b'*64+'.json'))
    symlink=cache/('c'*64+'.json');symlink.symlink_to(outside)
    m.prune_cache(str(cache),now,True);assert old.exists()
    m.prune_cache(str(cache),now);assert not old.exists() and fresh.exists() and outside.exists() and symlink.is_symlink() and (cache/'config.php').exists()
    write(cache/('d'*64+'.json'),'x'*20);m.prune_cache(str(cache),now,limit=10);assert not (cache/('d'*64+'.json')).exists()
    for i in range(5):write(cache/(('%064x'%i)+'.json'))
    m.prune_cache(str(cache),now,max_files=2);assert len([p for p in cache.iterdir() if p.name.endswith('.json') and len(p.stem)==64 and not p.is_symlink()])<=2
    jobs=cache/'batch-jobs';jobs.mkdir();lock=write(jobs/'worker.lock');done=write(jobs/('1'*32+'.json'),json.dumps({'status':'completed'}),8);active=write(jobs/('2'*32+'.json'),json.dumps({'status':'running'}),90)
    with open(str(lock),'r') as f:
        fcntl.flock(f,fcntl.LOCK_EX);m.prune_cache(str(cache),now);assert done.exists()
    m.prune_cache(str(cache),now);assert not done.exists() and active.exists() and lock.exists()
    backups=base/'backups';backups.mkdir();dirs=[]
    for i in range(10):
        p=backups/('mk-auth-mapa-clientes-202601%02d-000000-v1.0.0'%(i+1));p.mkdir();write(p/'copy','copy');write(p/'.mkauth-toolkit-backup-v1','mapa-clientes',40-i);write(p/'.mkauth-toolkit-backup-complete');dirs.append(p)
    manual=backups/'mk-auth-mapa-clientes-20250101-000000-v1.0.0';manual.mkdir();write(manual/'user-data');other=backups/'radius-backup';other.mkdir()
    m.prune_backups(str(backups),now,True);assert all(p.exists() for p in dirs)
    m.prune_backups(str(backups),now);assert sum(p.exists() for p in dirs)==2 and dirs[-1].exists() and dirs[-2].exists() and manual.exists() and other.exists()
    for i in range(2):
        p=backups/('mk-auth-mapa-clientes-2026030%d-000000-v1.0.0'%(i+1));p.mkdir();write(p/'.mkauth-toolkit-backup-v1','mapa-clientes',2);write(p/'failed-attempt')
    m.prune_backups(str(backups),now,limit=1);assert dirs[-1].exists() and dirs[-2].exists(), 'Failed attempts removed last successful rollback'
    tg=base/'telegram';tg.mkdir();write(tg/'config.json','token fixture');write(tg/'delivery.json');temp=write(tg/'.telegram-stale',days=2);m.prune_temporary(str(tg),now);assert not temp.exists() and (tg/'config.json').exists() and (tg/'delivery.json').exists()
    log=write(base/'maintenance.log','line\n'*100000);m.bounded_log(str(log),'ok');assert log.stat().st_size<256*1024 and log.read_text().endswith('ok\n')
    try:m.safe_dir('/var/tmp');raise AssertionError('broad root accepted')
    except ValueError:pass
    alias=base/'alias';alias.symlink_to(cache,target_is_directory=True)
    try:m.prune_cache(str(alias),now);raise AssertionError('symlink accepted')
    except ValueError:pass
print('PASS maintenance: TTL, byte/file caps, dry-run, active job locks, symlink isolation, backup markers/newest two, config preservation and bounded log')
