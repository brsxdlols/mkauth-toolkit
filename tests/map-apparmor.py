import importlib.util,pathlib,tempfile
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
