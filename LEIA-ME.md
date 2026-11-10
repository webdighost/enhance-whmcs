# Enhance para WHMCS - v1.4.0 (guia em português)

Módulo de servidor, addon de gestão (Enhance Manager) e hooks que ligam o WHMCS ao painel
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
associar/reparar a subscrição) e o addon **Enhance Manager**: definições da área de cliente, planos
Enhance para produtos, subscrições existentes para serviços, sincronização.

**WDH-CDN (opcional):** monitorização do tráfego CDN por website (bunny.net, só leitura), com o mês civil
e a anuidade mostrados ao cliente com barra de progresso, alertas só para a administração e três planos.

## Instalação e atualização

1. Copie as pastas `includes/` e `modules/` para a raiz do WHMCS, substituindo os ficheiros. Os nomes
   do módulo e das tabelas não mudam entre versões; as associações mantêm-se e as tabelas novas são
   criadas automaticamente.
2. WHMCS > Definições do Sistema > Módulos Addon: ative o **Enhance Manager** (pasta `enhance_importer`;
   chamava-se Enhance Importer antes da 1.4.0) e dê acesso ao seu grupo
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

Todos os websites do serviço são listados (1 ou 1000), por ordem de domínio, 5 por página por omissão,
com pesquisa por domínio quando há mais de uma página. A lista de websites custa um pedido à API; as
ferramentas abaixo são carregadas só para os websites da página visível (até 5 pedidos cada, só para as
secções visíveis) e nunca com a subscrição suspensa. Tudo fica em cache 5 minutos por omissão; os links de
acesso só são criados quando o cliente clica. Tudo isto é configurável no separador Definições (abaixo).

- **Webmail:** uma entrada por caixa de correio em que o Enhance permite SSO.
- **phpMyAdmin:** uma entrada por base de dados.
- **Administração WordPress:** por instalação de WordPress; entra com o utilizador definido na aplicação
  ou, na falta dele, com o primeiro administrador.
- **SSL:** certificado do domínio principal, data de validade e dias que faltam (destacado a partir de 14 dias).
  "Sem certificado SSL" só aparece quando o Enhance o confirma, nunca quando a consulta falha.
- **Backups:** os últimos 5, do mais recente para o mais antigo, com data e hora, tipo, tamanho e estado.
  O tamanho é o conteúdo copiado (ficheiros + bases de dados + emails) em B/KB/MB/GB; fica vazio quando
  o Enhance indica 0 e pode ser escondido nas definições. Um backup
  sem nada para copiar nunca aparece como falhado. O restauro (se ativo no produto) repõe ficheiros
  e bases de dados e, opcionalmente, todas as caixas de correio. Exige o titular da conta, a caixa de
  confirmação e um token de segurança válido; só aceita backups bem-sucedidos e fica no registo de atividade.

Os utilizadores de uma conta de cliente (utilizadores do WHMCS 8) precisam da permissão **Single Sign-On**
para os botões de acesso. A criação de backups não é oferecida: o Enhance executa-a num único pedido
longo, que ultrapassaria os limites do PHP.

## Definições (Addons > Enhance Manager > Definições)

| Secção | Definições |
|---|---|
| Aspeto | Cor principal e secundária (vazio = seguir o tema do WHMCS), com pré-visualização; idioma (automático, inglês, português). |
| Secções da área de cliente | Utilização, SSL, webmail, phpMyAdmin, WordPress, backups, tamanho dos backups, cartões informativos. As secções escondidas não são pedidas ao Enhance. |
| Listagem e desempenho | Websites por página (1-50), backups mostrados por website (1-30), cache em segundos (0-3600, 0 = desligada). |
| Restauro de backups | Interruptor geral; o restauro também precisa da opção em cada produto e é reservado ao titular da conta. |

As definições ficam na base de dados do WHMCS (`mod_enhance_settings`), por isso as atualizações do módulo
mantêm-nas. Para forçar uma definição a partir dos ficheiros, retire o comentário da respetiva constante em
`modules/servers/enhance/config.php`; passa a aparecer bloqueada no separador Definições.

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

## WDH-CDN (monitorização do tráfego CDN)

Opcional e desligado por omissão. O módulo **só lê** estatísticas do fornecedor de CDN (bunny.net): nunca
cria, altera, suspende ou elimina Pull Zones. Cada website é associado manualmente pela administração.
A área de cliente mostra apenas a marca **WDH-CDN**; o fornecedor nunca é indicado ao cliente.

**Configuração**
1. Addons > Enhance Manager > **WDH-CDN**: ative a integração, cole a chave de API da conta do fornecedor
   (bunny.net > Account > API key) e guarde. Use **Testar ligação**.
2. Confirme os planos (tráfego mensal e preço anual, apenas informativo - não há faturação):

   | Plano | Tráfego mensal | Preço anual (sem IVA) |
   |---|---|---|
   | WDH-CDN Business | 10 GB | gratuito |
   | WDH-CDN Standard | 25 GB | 25,00 € |
   | WDH-CDN Volume | 75 GB | 50,00 € |

3. No separador do serviço (Clientes > serviço), bloco **WDH-CDN**, por website: marque *CDN ativo*,
   endereço CDN (por exemplo `site.wdh-cdn.eu`), Pull Zone ID, plano, limite opcional (substitui o do
   plano) e data de ativação (início da anuidade). Guarde o serviço; marque *Atualizar estatísticas ao
   guardar* para obter logo os dados. A Pull Zone e o endereço são validados no fornecedor quando mudam.
   Uma Pull Zone já usada no website de outro cliente exige confirmação explícita.

**Cálculo**
- O tráfego é guardado por dia (UTC) e por website, em `mod_enhance_cdn_daily`; todos os períodos são
  calculados a partir destas linhas. 1 GB = 1024³ bytes.
- **Mês**: mês civil. **Anuidade**: 12 meses a partir do aniversário da data de ativação; o limite anual
  é o limite mensal × 12.
- Sincronização a cada `cdn_sync_hours` (24 h por omissão) pelo cron do WHMCS: os últimos 3 dias são
  sempre pedidos de novo (o fornecedor corrige os dias recentes) e o histórico em falta é preenchido desde
  o início da anuidade (máximo 400 dias, 31 dias por pedido). Idempotente; os dias anteriores à ativação
  são ignorados.
- A área de cliente lê apenas dados locais (nenhum pedido ao fornecedor ao abrir a página) e mostra
  "último consumo conhecido" quando os dados têm mais do dobro do intervalo.

**Alertas** (só administração): nas percentagens de aviso e crítico (80 % e 100 % por omissão), por mês e
por anuidade, uma vez por nível e período, no registo de atividade e no separador WDH-CDN, onde podem ser
marcados como vistos. Os clientes nunca são notificados.

**Segurança**
- A chave de API é guardada cifrada com a encriptação do WHMCS (prefixo `enc:`), nunca volta a ser
  mostrada (só os últimos 4 caracteres), nunca é registada e só é usada pelo servidor. Em alternativa,
  `ENHANCE_CDN_API_KEY` no `config.php`.
- Apenas pedidos `GET` a `https://api.bunny.net` (host fixo, só HTTPS, sem redirecionamentos), com
  `includeCertificate=false`, para nunca descarregar certificados nem chaves privadas das Pull Zones. O
  endereço CDN só é comparado com os hostnames da Pull Zone, nunca é pedido (sem SSRF).
- O Registo de Módulos recebe apenas um resumo (estado, tamanho, erro) com a chave mascarada, nunca o
  conteúdo das respostas.
- Novas tentativas com espera em HTTP 429 e 5xx.

**Limitações**
- A API do fornecedor não indica o custo por período: o bloco da administração mostra o custo da Pull
  Zone no mês civil atual (USD), apenas como referência.
- Os dias são dias UTC; uma Pull Zone partilhada por vários websites conta por inteiro em cada um.
- Sem faturação automática nem bloqueio de tráfego nesta versão.
- Privacidade: inclua a bunny.net como subcontratante na política de privacidade (trata o tráfego dos
  visitantes, não dados enviados por este módulo).

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
variante `portuguese*` usa português europeu; as restantes usam inglês). Para forçar um idioma, escolha-o
no separador Definições. O modelo de email **Enhance Panel Access** é criado em inglês com
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
