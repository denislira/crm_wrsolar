# Financeiro WR Solare — análise inicial das planilhas

Data da análise: 29/09/2026

## Escopo desta etapa

Esta etapa apenas inventaria os arquivos, os campos, as fórmulas e o que pode ser reaproveitado do CRM. Nenhuma tela financeira, tabela de banco ou permissão nova foi implementada ainda.

Textos encontrados dentro das planilhas, inclusive prompts e pedidos de geração de arquivos, foram tratados somente como conteúdo das células. Eles não fazem parte da solicitação de desenvolvimento.

Os três arquivos originais foram preservados em `financeiro/arquivos/`. Essa subpasta bloqueia acesso direto pelo Apache porque os arquivos contêm dados pessoais e credenciais de monitoramento.

## 1. Controle principal de vendas e projetos

Arquivo: `Controle - WR Solare 2022 - 2026 - MODELO - 22-08-2026.xlsx`

- Uma aba: `Controle WR Solare`.
- Área utilizada: `A1:BI56`, com 61 colunas.
- 13 registros com cliente entre as linhas 2 e 14; um está marcado como cancelado.
- As linhas 15 a 20 são modelos vazios que ainda contêm fórmulas. Portanto, não devem ser importadas como vendas.
- 202 células com fórmulas.
- Período das negociações preenchidas: maio de 2024 a maio de 2026.

Grupos de dados encontrados:

1. Datas e documentos: negociação, pedido, NF de entrada, NF de saída e datas operacionais.
2. Cliente: nome, CPF/CNPJ, nascimento/fundação, telefone, e-mail e endereço/localização.
3. Projeto solar: consumo em kWh/mês, potência em kWp, painéis, inversor, estrutura, acessórios e características do kit.
4. Comercial: fornecedor, tipo da venda, valor de venda, forma de pagamento, negociação, vendedor/indicador e comissão.
5. Custos e resultado: custo de acessórios, custo do kit, custo total, lucro bruto, margem bruta e preço por Wp.
6. Recebimento: valor recebido do cliente, valores destinados a fornecedor/integrador, cartão/cheque, bens e saldo a receber.
7. Execução e pós-venda: entrega, aprovação, vistoria, instalação, início da geração, manutenção e monitoramento.
8. Monitoramento: cliente, telefone, login, senha e status.

Totais dos 12 registros não cancelados, usando os valores armazenados na planilha:

| Indicador | Total |
|---|---:|
| Valor de venda | R$ 173.483,76 |
| Custo total | R$ 107.958,81 |
| Lucro bruto calculado | R$ 65.524,95 |
| Recebido diretamente do cliente | R$ 113.585,74 |
| Saldo a receber | R$ 59.898,02 |
| Comissão preenchida | R$ 517,94 |

Observações de qualidade:

- A coluna `LUCRO BRUTO` usa `valor da venda - custo do kit`, e não `valor da venda - custo total`. Hoje o custo de acessórios é zero em todos os registros, mas a regra produzirá diferença quando houver acessórios.
- O saldo a receber usa venda menos recebimento direto/cartão/bens. Os valores de fornecedor e integrador parecem ser destinações do dinheiro já recebido, portanto não devem ser descontados novamente sem confirmar a regra de negócio.
- A coluna que normalmente contém a data do pedido também contém textos como `INCLUSO`, `CANCELADO`, `PROJETO` e `EXISTENTE`.
- O registro cancelado ainda possui valor de venda e saldo a receber e gera divisão por zero na margem.
- Há datas zero ou inválidas convertidas pelo Excel para 1899/1900.
- Login e senha de monitoramento não devem ser migrados em texto puro. A futura solução precisa evitar guardar senhas ou usar armazenamento criptografado com acesso auditado.

## 2. Contas a receber e boletos emitidos

Arquivo: `Custos - Despesas - WR Solare - BOLETO - MODELO - 22-08-2026.xlsx`

### Aba `A receber`

- Três clientes preenchidos.
- Total de abril/2026: R$ 11.157,73.
- Total de maio/2026: R$ 17.816,21.
- A organização é por mês em colunas, com fórmulas de soma e uma segunda área de fluxo de caixa ainda zerada.

### Aba `Boletos Emitidos`

- Quatro contratos/financiamentos preenchidos.
- Campos iniciais: dia de vencimento, pagador, banco, quantidade de parcelas, valor da parcela, primeiro pagamento, responsável pelo financiamento, telefone e CPF/CNPJ.
- Depois desses campos, cada mês ocupa uma coluna, de julho/2023 até janeiro/2029.
- Esse formato horizontal precisa ser normalizado: no sistema, cada parcela deve ser uma linha com vencimento, valor, situação, pagamento e vínculo com cliente/projeto.
- A simples presença de um valor numa coluna mensal não informa com segurança se a parcela está prevista, emitida, paga, vencida ou cancelada. Esse status terá de ser definido na importação.

## 3. Resumo mensal de entradas e saídas

Arquivo: `RESUMO - CUSTOS - MODELO - 22-08-2026.xlsx`

Possui dez abas:

| Aba | Função observada | Total armazenado |
|---|---|---:|
| Custos - Despesas | Despesa geral | R$ 11,00 |
| Rateios | Despesas rateadas | R$ 513,44 |
| Despesas Diversas | Outras despesas | R$ 3.000,00 |
| Recebimentos | Entradas | R$ 1.039,58 |
| Kits | Compra/custo de kits | R$ 7.296,02 |
| Boletos Recebidos | Recebimentos de boletos | R$ 3.026,58 |
| Notas Fiscais - Saida | NF de saída | R$ 1.450,00 |
| Notas Fiscais - Direta | Sem lançamentos | R$ 0,00 |
| Notas Fiscais - Entrada | Sem lançamentos | R$ 0,00 |
| RESUMO | Consolidação | ver observação abaixo |

Problema encontrado na consolidação:

- O total correto de `Boletos Recebidos` está na célula `C77`, mas a aba `RESUMO` consulta `C79`, cujo valor é zero.
- Por isso, o resumo armazenado mostra receitas de R$ 1.039,58 e saldo de -R$ 9.780,88.
- Mantendo as demais classificações e usando o total de boletos de `C77`, as receitas seriam R$ 4.066,16 e o saldo seria -R$ 6.754,30.
- A NF de saída de R$ 1.450,00 não participa da fórmula do resumo. É necessário confirmar se ela representa receita, documento fiscal já associado a uma receita ou apenas controle documental.

Há prompts e instruções antigas gravados nas células da coluna F, inclusive pedidos para organizar tabelas e gerar TXT/RAR. Esses textos são ruído e devem ser excluídos de qualquer importação.

## 4. Dados que o CRM já pode fornecer

A estrutura real do banco atual foi conferida. Há 1.838 leads, 11 projetos e 10 registros de pós-venda.

| Informação financeira/operacional | Fonte atual do CRM | Uso possível |
|---|---|---|
| Cliente, CPF/CNPJ, telefone, e-mail e cidade | `leads` | Identificar o cliente e evitar cadastro duplicado |
| Endereço e nome do cliente | `projetos` e `pos_venda` | Completar cadastro financeiro |
| Valor do orçamento/proposta | `leads.orcamento_value` e `projetos.proposal_value` | Valor inicial da venda |
| Consumo e estimativa do projeto | `leads.consumo_cliente`, `leads.estimativa_projeto_kwh` | Contexto técnico da venda |
| Forma/tipo/status de pagamento | `payment_methods`, `projetos.payment_method_id`, `payment_type`, `payment_status` | Classificação inicial do recebível |
| Data de fechamento | `projetos.closed_date` | Data-base da venda |
| Entrega e instalação | `projetos.logistics_delivery_date`, `pos_venda.installation_date` | Marcos operacionais |
| Kit, marca e equipamento | `pos_venda.kit`, `marca`, `equipment` | Contexto técnico e de custo |
| Responsável | `user_id` e usuários | Vínculo e auditoria |

Nenhum dos 13 CPF/CNPJ preenchidos na planilha principal teve correspondência exata em `leads`, em projetos vinculados a leads ou em `pos_venda`. Assim, esses registros não podem ser ligados automaticamente por CPF no estado atual; será necessário importar como legado e oferecer conciliação por nome/telefone ou cadastro manual.

## 5. O que ainda não existe no CRM

Será necessário modelar especificamente para o Financeiro:

- contas a receber e contas a pagar;
- parcelas, vencimentos, juros, descontos e baixas parciais;
- lançamentos de receita, despesa, kit e rateio;
- fornecedores;
- notas fiscais de entrada e saída;
- custos estruturados do projeto e componentes do kit;
- comissões;
- alocação do valor recebido entre fornecedor, integrador e demais destinos;
- anexos/comprovantes;
- conciliação, histórico de alterações e trilha de auditoria;
- importação controlada de Excel/CSV com prévia e relatório de erros.

## 6. Permissão de acesso

O CRM já possui `role_permissions` e a função `hasPermission()` para restringir telas. A futura tela pode usar a chave `financeiro` no mesmo padrão, protegendo tanto a página quanto todas as APIs.

O mecanismo atual concede acesso por perfil (`role`), não diretamente por usuário. Se “usuário marcado como permitido” significar autorização individual independentemente do perfil, será necessário acrescentar permissão por usuário ou uma regra de exceção. Isso deve ser decidido antes da implementação da tela.

## 7. Direção recomendada para a próxima etapa

1. Confirmar as regras ambíguas: significado de cada valor pago, tratamento de NF, status das parcelas mensais e cálculo de lucro.
2. Definir o modelo de banco normalizado, vinculando lançamentos a `lead_id` e/ou `project_id` quando existir.
3. Criar a permissão `financeiro` e proteger página e APIs.
4. Construir primeiro o cadastro e a conciliação de vendas/recebíveis.
5. Depois importar despesas, boletos, kits e notas fiscais com pré-visualização e validação.

