#!/usr/bin/env python3
"""Grant native PHP profiles access only to the private Telegram state directory."""
import argparse,os,pathlib,re,shutil,subprocess

def configure(state,backup,root=pathlib.Path('/etc/apparmor.d'),reload_profile=None):
    state=str(state).rstrip('/')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+',state) or state in ('/var','/var/lib','/tmp','/var/tmp','/opt','/opt/mk-auth') or '..' in state.split('/'):raise ValueError('Unsafe Telegram state path')
    if pathlib.Path(state).resolve()!=pathlib.Path(state):raise ValueError('Symbolic Telegram state path')
    profiles=[root/n for n in ('sistema.php-admin','sistema.php-central') if (root/n).is_file()]
    if not profiles:return 0
    local=root/'local/mkauth-map-telegram'
    if local.is_symlink() or any(p.is_symlink() for p in profiles):raise ValueError('Symbolic AppArmor policy refused')
    policy='# Managed by mkauth-toolkit: private Telegram state only.\nowner '+state+'/ rw,\nowner '+state+'/** rwk,\n'
    if local.exists() and not local.read_text().startswith('# Managed by mkauth-toolkit:'):raise ValueError('Existing unmanaged AppArmor rule preserved')
    original={p:p.read_bytes() for p in profiles};oldlocal=local.read_bytes() if local.exists() else None
    backup.mkdir(parents=True,exist_ok=True);os.chmod(str(backup),0o700)
    for p in profiles:shutil.copy2(str(p),str(backup/p.name))
    if oldlocal is not None:shutil.copy2(str(local),str(backup/'local-rule-before'))
    reload_profile=reload_profile or (lambda p:subprocess.run(['apparmor_parser','-r',str(p)],check=True,stdout=subprocess.DEVNULL))
    include='  #include <local/mkauth-map-telegram>'
    try:
        local.parent.mkdir(parents=True,exist_ok=True);local.write_text(policy);os.chmod(str(local),0o644)
        for p in profiles:
            text=original[p].decode()
            if include.strip() not in text:
                at=text.rfind('}')
                if at<0:raise ValueError('Unrecognized PHP profile')
                p.write_text(text[:at]+include+'\n'+text[at:])
            reload_profile(p)
    except Exception:
        for p,data in original.items():p.write_bytes(data)
        if oldlocal is None:
            if local.exists():local.unlink()
        else:local.write_bytes(oldlocal)
        for p in profiles:reload_profile(p)
        raise
    return len(profiles)

if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('--state',required=True);a.add_argument('--backup',required=True);a.add_argument('--profiles',default='/etc/apparmor.d');args=a.parse_args()
    if os.geteuid()!=0:raise SystemExit('Execute como root')
    enabled=pathlib.Path('/sys/module/apparmor/parameters/enabled')
    if args.profiles=='/etc/apparmor.d' and (not enabled.is_file() or enabled.read_text().strip()!='Y'):
        print('Telegram: AppArmor inativo; perfis do sistema preservados.')
        raise SystemExit(0)
    count=configure(args.state,pathlib.Path(args.backup),pathlib.Path(args.profiles))
    print('Telegram: AppArmor atualizado com acesso restrito à pasta privada em '+str(count)+' perfis PHP.')
