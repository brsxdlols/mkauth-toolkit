#!/usr/bin/env python3
"""Grant native PHP profiles access only to the private Telegram state directory."""
import argparse,os,pathlib,re,shutil,subprocess,sys

def find_parser():
    found=shutil.which('apparmor_parser')
    if found:return found
    for candidate in ('/sbin/apparmor_parser','/usr/sbin/apparmor_parser','/usr/local/sbin/apparmor_parser'):
        if os.path.isfile(candidate) and os.access(candidate,os.X_OK):return candidate
    return None

def php_confined(security_profiles=pathlib.Path('/sys/kernel/security/apparmor/profiles'),proc=pathlib.Path('/proc')):
    # The module may be enabled while no PHP profile is loaded.
    loaded_known=False;other_php=False
    try:
        loaded=security_profiles.read_text();loaded_known=True
        if any('php-admin' in line or 'php-central' in line for line in loaded.splitlines()):return True
        other_php=any('php' in line or 'hhvm' in line for line in loaded.splitlines())
    except OSError:pass
    observed=False;unknown=False
    try:processes=list(proc.iterdir())
    except OSError:return None
    for process in processes:
        if not process.name.isdigit():continue
        try:
            comm=(process/'comm').read_text().strip()
            if not (comm.startswith('php') or comm.startswith('hhvm')):continue
            current=(process/'attr/current').read_text().strip();observed=True
            if 'php-admin' in current or 'php-central' in current:return True
            if current!='unconfined':unknown=True
        except OSError:continue
    if observed:return None if unknown else False
    return False if loaded_known and not other_php else None

def configure(state,backup,root=pathlib.Path('/etc/apparmor.d'),reload_profile=None):
    state=str(state).rstrip('/')
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+',state) or state in ('/var','/var/lib','/tmp','/var/tmp','/opt','/opt/mk-auth') or '..' in state.split('/'):raise ValueError('Unsafe Telegram state path')
    if pathlib.Path(state).resolve()!=pathlib.Path(state):raise ValueError('Symbolic Telegram state path')
    profiles=[root/n for n in ('sistema.php-admin','sistema.php-central') if (root/n).is_file()]
    if not profiles:return 0
    if reload_profile is None:
        parser=find_parser()
        if not parser:
            status=php_confined()
            if status is False:
                print('Telegram: PHP sem perfil AppArmor ativo e apparmor_parser ausente; perfis preservados. Instalacao pode continuar.')
                return 0
            raise RuntimeError('apparmor_parser ausente e nao foi possivel confirmar PHP sem confinamento. Instale o pacote apparmor e execute novamente o instalador; nenhum perfil foi alterado.')
        reload_profile=lambda p:subprocess.run([parser,'-r',str(p)],check=True,stdout=subprocess.DEVNULL)
    local=root/'local/mkauth-map-telegram'
    if local.is_symlink() or any(p.is_symlink() for p in profiles):raise ValueError('Symbolic AppArmor policy refused')
    policy='# Managed by mkauth-toolkit: private Telegram state only.\nowner '+state+'/ rw,\nowner '+state+'/** rwk,\n'
    if local.exists() and not local.read_text().startswith('# Managed by mkauth-toolkit:'):raise ValueError('Existing unmanaged AppArmor rule preserved')
    original={p:p.read_bytes() for p in profiles};oldlocal=local.read_bytes() if local.exists() else None
    backup.mkdir(parents=True,exist_ok=True);os.chmod(str(backup),0o700)
    for p in profiles:shutil.copy2(str(p),str(backup/p.name))
    if oldlocal is not None:shutil.copy2(str(local),str(backup/'local-rule-before'))
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
    except Exception as original_error:
        for p,data in original.items():p.write_bytes(data)
        if oldlocal is None:
            if local.exists():local.unlink()
        else:local.write_bytes(oldlocal)
        rollback_errors=[]
        for p in profiles:
            try:reload_profile(p)
            except Exception as error:rollback_errors.append(str(error))
        if rollback_errors:
            raise RuntimeError('Falha ao carregar AppArmor: '+str(original_error)+'. Arquivos restaurados; falha ao recarregar a politica anterior: '+'; '.join(rollback_errors)) from None
        raise
    return len(profiles)

if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('--state',required=True);a.add_argument('--backup',required=True);a.add_argument('--profiles',default='/etc/apparmor.d');args=a.parse_args()
    if os.geteuid()!=0:raise SystemExit('Execute como root')
    enabled=pathlib.Path('/sys/module/apparmor/parameters/enabled')
    if args.profiles=='/etc/apparmor.d' and (not enabled.is_file() or enabled.read_text().strip()!='Y'):
        print('Telegram: AppArmor inativo; perfis do sistema preservados.')
        raise SystemExit(0)
    try:count=configure(args.state,pathlib.Path(args.backup),pathlib.Path(args.profiles))
    except Exception as error:
        print('Telegram: '+str(error),file=sys.stderr)
        raise SystemExit(1)
    if count==0:raise SystemExit(0)
    print('Telegram: AppArmor atualizado com acesso restrito à pasta privada em '+str(count)+' perfis PHP.')
