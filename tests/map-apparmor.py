import importlib.util,pathlib,tempfile
from unittest import mock
source=pathlib.Path(__file__).resolve().parents[1]/'installers/configure-map-apparmor.py'
spec=importlib.util.spec_from_file_location('policy',source);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory() as temp:
    root=pathlib.Path(temp);profiles=root/'policies';profiles.mkdir()
    original='/native/php-central {\n  /native/** r,\n}\n'
    p=profiles/'sistema.php-central';p.write_text(original);calls=[]
    assert m.configure('/var/lib/mkauth-map-telegram',root/'backup',profiles,lambda p:calls.append(p))==1
    assert '/native/** r,' in p.read_text() and p.read_text().count('#include <local/mkauth-map-telegram>')==1
    rule=(profiles/'local/mkauth-map-telegram').read_text();assert 'owner /var/lib/mkauth-map-telegram/** rwk,' in rule and '/var/lib/**' not in rule
    m.configure('/var/lib/mkauth-map-telegram',root/'backup2',profiles,lambda p:None);assert p.read_text().count('#include <local/mkauth-map-telegram>')==1
    p.write_text(original);(profiles/'local/mkauth-map-telegram').unlink();attempt=[0]
    def failure(p):
        attempt[0]+=1
        if attempt[0]==1:raise RuntimeError('fixture parser failure')
    try:m.configure('/var/lib/mkauth-map-telegram',root/'backup3',profiles,failure);raise AssertionError('failure ignored')
    except RuntimeError:pass
    assert p.read_text()==original and not (profiles/'local/mkauth-map-telegram').exists()
    for unsafe in ['/var/lib','/var/tmp','/tmp','/var/lib/../etc','/var/lib/invalid\n/path']:
        try:m.configure(unsafe,root/'backup4',profiles,lambda p:None);raise AssertionError('unsafe path accepted')
        except ValueError:pass
print('PASS AppArmor: narrow owner rules, native policy preserved, idempotence, backup/rollback and unsafe paths refused')

with tempfile.TemporaryDirectory() as temp:
    root=pathlib.Path(temp);profiles=root/'policies';profiles.mkdir();p=profiles/'sistema.php-admin';original='/native/php-admin {\n /native/** r,\n}\n';p.write_text(original)
    with mock.patch.object(m,'find_parser',return_value=None),mock.patch.object(m,'php_confined',return_value=False):
        assert m.configure('/var/lib/mkauth-map-telegram',root/'skipped',profiles)==0
    assert p.read_text()==original and not (root/'skipped').exists() and not (profiles/'local').exists()
    for state in (True,None):
        with mock.patch.object(m,'find_parser',return_value=None),mock.patch.object(m,'php_confined',return_value=state):
            try:m.configure('/var/lib/mkauth-map-telegram',root/'refused',profiles);raise AssertionError('active/unknown confinement ignored')
            except RuntimeError as e:assert 'apparmor_parser' in str(e)
        assert p.read_text()==original and not (profiles/'local').exists()
    with mock.patch.object(m.shutil,'which',return_value=None),mock.patch.object(m.os.path,'isfile',side_effect=lambda p:p=='/sbin/apparmor_parser'),mock.patch.object(m.os,'access',return_value=True):assert m.find_parser()=='/sbin/apparmor_parser'
    def always_fail(p):raise FileNotFoundError('fixture parser disappeared')
    try:m.configure('/var/lib/mkauth-map-telegram',root/'rollback',profiles,always_fail);raise AssertionError('failure ignored')
    except RuntimeError as e:assert 'fixture parser disappeared' in str(e) and 'Arquivos restaurados' in str(e)
    assert p.read_text()==original and not (profiles/'local/mkauth-map-telegram').exists()
    proc=root/'proc';proc.mkdir();entry=proc/'123';(entry/'attr').mkdir(parents=True);(entry/'comm').write_text('php-fpm\n');(entry/'attr/current').write_text('unconfined\n');missing=root/'unmounted-securityfs'
    assert m.php_confined(missing,proc) is False
    (entry/'attr/current').write_text('/opt/mk-auth/hhvm/8-0-30/bin/php-admin (enforce)\n');assert m.php_confined(missing,proc) is True
    (entry/'attr/current').write_text('other-custom-php-profile (enforce)\n');assert m.php_confined(missing,proc) is None
    empty=root/'empty-proc';empty.mkdir();assert m.php_confined(missing,empty) is None
    loaded=root/'loaded-profiles';loaded.write_text('/opt/mk-auth/hhvm/8-0-30/bin/php-central (enforce)\n');assert m.php_confined(loaded,empty) is True
    loaded.write_text('/usr/sbin/php-fpm (enforce)\n');assert m.php_confined(loaded,empty) is None
print('PASS missing parser: unconfined PHP skips without mutations, active/unknown fails safely, absolute parser path, original failure retained and files restored')
