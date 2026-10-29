# Enhance para WHMCS - v1.4.0 (guia em português)

Módulo de servidor, addon importador e hooks que ligam o WHMCS ao painel
[Enhance](https://enhance.com) através da API REST. Interface em inglês com tradução completa em
português europeu. Não precisa de Composer nem de bibliotecas externas no WHMCS.

Histórico de versões: [CHANGELOG.md](CHANGELOG.md) (em inglês).

## Funcionalidades

**Provisionamento:** criar (organização, login do titular, subscrição, website), suspender, reativar,
terminar (suave ou definitivo), mudar de plano, atualização da utilização (disco e tráfego) e
sincronização diária do estado. O Criar pode ser repetido em segurança depois de uma interrupção e corre
com um bloqueio por cliente.

**Área de cliente:** utilização, websites com versão do PHP, validade do certificado SSL, entrada no
painel de controlo (como titular da organização), entrada direta no webmail, phpMyAdmin e administração
do WordPress, backups recentes e restauro (opcional).

**Administração:** teste de ligação com diagnóstico passo a passo, painel no perfil do cliente (estado da
organização, suspender/reativar, link para definir a palavra-passe, pesquisar e associar organizações),
separador do serviço (organização, subscrição, website, última ação do módulo e último erro,
associar/reparar a subscrição) e addon importador (planos Enhance para produtos, subscrições existentes
para serviços, sincronização).

## Instalação e atualização

1. Copie as pastas `includes/` e `modules/` para a raiz do WHMCS, substituindo os ficheiros. Os nomes
   do módulo e das tabelas não mudam entre versões; as associações mantêm-se e as tabelas novas são
   criadas automaticamente.
2. WHMCS > Definições do Sistema > Módulos Addon: ative o **Enhance Importer** e dê acesso ao seu grupo
   de administradores.
3. Configure o servidor Enhance (abaixo) e clique em **Testar ligação**.

### Servidor

WHMCS > Definições do Sistema > Servidores > Adicionar Novo Servidor, módulo **Enhance**:

| Campo WHMCS | Valor |
|-------------|-------|
| Hostname    | Apenas o domínio do painel, ex.: `cp.exemplo.pt` (sem `https://` nem caminho). |
| Username    | UUID da **organização principal** (a organização dona do token). |
| Access Hash | Token de acesso à API do Enhance. |
| Password    | Vazio (ou o token aqui, cifrado pelo WHMCS, deixando o Access Hash vazio). |
| Secure      | Assinalado. |

Crie o token em Enhance > Settings > Access tokens (`https://<painel>/settings/access-tokens`) da **organização principal**, com a função
**Super Admin**; o token só é mostrado uma vez. Os tokens podem ter **data de validade** e
**restrição de IP**: um token expirado, ou que não autorize o IP público do servidor WHMCS, é recusado
tal como um token errado. Atenção ao preenchimento automático do browser no formulário do servidor:
pode pôr a sua palavra-passe do WHMCS no campo Password e o seu utilizador no Username.

### Produto (Definições do Módulo)

| Opção | Guardada em | Descrição |
|---|---|---|
| Plano Enhance | configoption1 | Plano da organização principal (carregado do Enhance). Igual ao módulo oficial. |
| Modo de terminação | configoption2 | Suave (mantém os dados) ou Definitivo (elimina websites e apaga a subscrição). |
| Grupo de servidores | configoption3 | Onde os novos websites são criados; por omissão, o definido no plano. |
| Restauro de backups pelo cliente | configoption4 | Permite ao titular da conta restaurar backups na área de cliente. Desligado por omissão. |

## Ferramentas na área de cliente

Aparecem para até 5 websites ativos do serviço e nunca com a subscrição suspensa. Os dados ficam em
cache 5 minutos; os links de acesso só são criados quando o cliente clica.

- **Webmail:** uma entrada por caixa de correio em que o Enhance permite SSO.
- **phpMyAdmin:** uma entrada por base de dados.
- **Administração WordPress:** por instalação de WordPress; entra com o utilizador definido na aplicação
  ou, na falta dele, com o primeiro administrador.
- **SSL:** certificado do domínio principal, data de validade e dias que faltam (destacado a partir de 14 dias).
- **Backups:** os últimos 5, com tipo, tamanho e estado. O restauro (se ativo no produto) repõe ficheiros
  e bases de dados e, opcionalmente, todas as caixas de correio. Exige o titular da conta, a caixa de
  confirmação e um token de segurança válido; só aceita backups bem-sucedidos e fica no registo de atividade.

Os utilizadores de uma conta de cliente (utilizadores do WHMCS 8) precisam da permissão **Single Sign-On**
para os botões de acesso. A criação de backups não é oferecida: o Enhance executa-a num único pedido
longo, que ultrapassaria os limites do PHP.

## Separador do serviço (administração)

- Dados da organização, subscrição, servidor e website.
- **Última ação do módulo:** ação, resultado e data, mais o último erro e a respetiva mensagem (mantido
  até haver um erro mais recente), para ver falhas sem ligar o Registo de Módulos.
- **Associar subscrição:** escolher outra subscrição da organização do cliente, ou remover a associação
  (nada é eliminado no Enhance). Aplicado ao guardar o serviço; subscrições associadas a outros serviços
  aparecem mas não podem ser escolhidas.
- O botão de entrada no painel que o WHMCS mostra na página do serviço no admin usa a mesma função de SSO
  da área de cliente: o administrador entra como o cliente (titular da organização), e isso fica registado
  no registo de atividade.

## Substituir o módulo oficial do Enhance (1.1.x)

Mesmo nome de módulo (`modules/servers/enhance`) e mesma opção de plano (`configoption1`), por isso os
produtos existentes continuam a funcionar. Na primeira utilização o módulo migra os dados do oficial: o
campo de cliente `enhOrgId` e os campos de produto `enhanceSubscriptionId` / `subscriptionId` (só em
produtos que usam o módulo Enhance). Apague `modules/servers/enhance` (incluindo a pasta `api/`) antes de
copiar esta versão.

| | Oficial 1.1.4 | Este módulo |
|---|---|---|
| Titular | membro criado com a função `Owner` | igual; organizações sem titular são reparadas; confirmado na API |
| Palavra-passe | a palavra-passe do serviço WHMCS é usada no login Enhance | aleatória, nunca enviada; o Enhance envia um link para a definir |
| Website falhado | ficam organização, login e subscrição | a subscrição é anulada; o Criar pode ser repetido |
| Concorrência | nenhuma | bloqueio por cliente |
| Terminação | suave | suave ou definitiva |
| Área de cliente | link de entrada | utilização, SSL, webmail, phpMyAdmin, WordPress, backups |

## Idioma

Segue o WHMCS: idioma do administrador no admin e idioma do visitante na área de cliente (qualquer
variante `portuguese*` usa português europeu; as restantes usam inglês). Para forçar um idioma, defina
`ENHANCE_LANGUAGE` em `config.php`. O modelo de email **Enhance Panel Access** é criado em inglês com
tradução `portuguese-pt`. Campos: `{$panel_url}`, `{$login_email}`, `{$new_login}`.

## API

As 39 chamadas à API foram conferidas com a especificação OpenAPI oficial (https://apidocs.enhance.com,
orchd **12.26.3**). A autenticação é `Authorization: Bearer <token>` para `https://<hostname>/api/`.
Comportamento observado num painel Enhance real e tratado pelo módulo: o `PUT /orgs/{id}/owner` é recusado
a tokens de acesso (403) e a alteração de membros não aceita a função `Owner`; por isso o titular é criado
como membro com essa função. As chamadas aparecem em Utilitários > Registos > Registo de Módulos quando o
registo está ativo; tokens e palavras-passe são sempre ocultados.

## Desenvolvimento

```bash
tests/run.sh                        # todos os testes (PHP >= 8.1, Composer, Python 3, OpenSSL)
ENHANCE_TEST_MYSQL=1 tests/run.sh   # também o teste do bloqueio em MySQL/MariaDB (base whmcs_test, utilizador t/t)
./build.sh                          # dist/enhance-whmcs-<versão>.zip
```

Os testes nunca contactam um painel Enhance real. Usam uma simulação HTTPS local da API (com os
comportamentos vistos em painéis reais), o Eloquent real sobre SQLite para as tabelas do WHMCS, o Smarty
real para o template da área de cliente, o `php -S` para verificar os redirecionamentos SSO reais, e
processos simultâneos reais em MariaDB para o bloqueio. O GitHub Actions (`.github/workflows/ci.yml`)
verifica ainda a sintaxe em PHP 7.4 a 8.3 e corre o PHPCompatibility. Teste sempre num WHMCS de testes
antes de produção.
