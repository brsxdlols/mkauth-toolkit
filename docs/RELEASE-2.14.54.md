# v2.14.54 — Instalador em servidores sem apparmor_parser

Corrige a instalação quando o módulo AppArmor está habilitado, mas o PHP está sem confinamento e o apparmor_parser não existe. A presença de arquivos de perfil deixa de ser tratada como prova de confinamento: o instalador verifica os perfis carregados e, se necessário, o estado dos processos PHP. Quando confirma PHP sem confinamento, preserva as políticas e continua a instalação normalmente.

O comando é procurado também em caminhos administrativos absolutos, mesmo fora do PATH. Se o PHP estiver confinado ou o estado for desconhecido, a instalação para antes de alterar os perfis e informa a dependência necessária. Falhas de recarga restauram os arquivos e preservam o erro original, mesmo se a recarga da política anterior também falhar.

Testes em Linux/Python 3.7: PHP sem confinamento, AppArmor ativo/desconhecido, ausência de comando, caminho absoluto, permissões restritas, idempotência e restauração. Validado no servidor KMNET sem instalar pacotes ou desativar proteções. Mapa permanece na versão 1.3.54; configurações locais preservadas.
