# Financeiro web — estado em 29/09/2026

## Implementado

- Entrada automática de leads ganhos do CRM, com conferência/aceite e congelamento dos dados financeiros após o aceite.
- Cadastro de venda com os 60 campos não sensíveis do controle principal; a coluna de senha de monitoramento não é armazenada.
- Cálculos de potência (painéis × W / 1.000), kWh/mês (regra da planilha: kWp / 8 × 1.000), custo total, lucro, margem sobre custo, preço/Wp, tempo de execução e saldos a partir de parcelas e baixas.
- Plano de recebimento em entrada e até 120 parcelas, com arredondamento em centavos e datas mensais.
- Contas a receber/pagar, lançamentos manuais, juros, descontos, baixa parcial, histórico, cancelamento sem baixas, filtro e paginação.
- Resumo mensal realizado versus previsto, por categoria, e cadastro de notas fiscais como documentos (sem duplicar entradas de caixa).
- Importação CSV/XLSX com prévia e confirmação para vendas, títulos e notas em tabelas simples. A venda importada entra em conferência; linhas canceladas do controle principal são ignoradas.
- Restrição de acesso por perfil **e** por usuário. A direção gerencia o acesso individual em `financeiro_acessos.php`.
- Auditoria dos lançamentos e da concessão/revogação de acesso.

## Regras adotadas

- `financeiro_baixas.valor` é o dinheiro que entrou/saiu. O principal quitado é `valor - juros + desconto`.
- Recebimentos de cartão/cheque/bens são meios de recebimento. Pagamentos a fornecedor/integrador são saídas, não receita adicional.
- Notas fiscais não alteram o caixa. O caixa muda apenas com a baixa do título.
- O lucro web usa venda menos custo de kit **e acessórios**. A planilha antiga subtraía apenas o kit; foi adotado custo total para não superestimar lucro.
- O resumo previsto usa o saldo aberto de títulos com vencimento no mês. O realizado usa a data da baixa.

## Limites conhecidos (não tratar como concluídos)

- Os XLSX com várias abas são lidos somente pela primeira aba. Para abas adicionais, exportar cada aba como CSV; os formatos horizontais de `Boletos Emitidos` e `A receber` precisam de transformação específica antes da importação.
- Importação de baixas legadas e anexos/comprovantes ainda não está disponível.
- A prévia exibe 10 linhas, mas não faz conciliação avançada entre pessoas/projetos homônimos. O mesmo arquivo é bloqueado por hash; vendas com mesmo CPF/valor/data também são ignoradas.
- O resumo é operacional, não substitui conciliação bancária nem demonstrativo contábil.
- O cadastro de NF usa número, data, contraparte e valor; impostos, XML e exigências fiscais específicas não foram modelados.

## Banco

Em uma instalação nova, executar nesta ordem: `database/create_financeiro_base.sql`, `database/financeiro_operacional.sql` e `database/financeiro_acesso_usuario.sql`. Os dois últimos foram aplicados ao banco local nesta etapa. `financeiro_acesso_usuario.sql` adiciona coluna e deve ser executado uma única vez.
