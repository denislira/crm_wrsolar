# Fluxo CRM → Financeiro

Data da análise: 29/09/2026

## Fluxo atual encontrado no CRM

### 1. Gestão de leads

O funil atual segue esta ordem:

1. EM ATENDIMENTO
2. QUALIFICADO
3. PROPOSTA ENVIADA
4. EM NEGOCIAÇÃO
5. FINANCIAMENTO
6. CONSÓRCIO
7. ASSINATURA
8. DESISTIU
9. FEZ COM OUTRA EMPRESA
10. AGUARDARÁ...
11. VENDA CONCLUÍDA

`VENDA CONCLUÍDA` está corretamente configurada com:

- `final_type = won`;
- `is_conversion = 1`;
- `allow_project_creation = 1`.

Apesar do nome, o campo legado `is_final` está zerado nessa etapa e também nas etapas de perda. Por isso, automações novas não devem depender somente de `is_final`; devem usar `is_conversion = 1` ou `final_type = 'won'`.

Ao chegar em `VENDA CONCLUÍDA`, o CRM apenas libera o botão `Criar Projeto`. O projeto não é criado automaticamente.

### 2. Projetos

O projeto pode ser criado a partir do lead convertido ou diretamente na tela de Projetos. Quando nasce a partir do lead, o CRM copia principalmente:

- nome do cliente;
- valor do orçamento;
- estimativa do projeto;
- cidade/endereço disponível;
- vínculo com `lead_id`.

As etapas atuais de projeto são:

1. Documentação — etapa inicial;
2. Homologação;
3. Logistica;
4. Vistoria.

O cadastro de projeto possui valor da proposta, forma de pagamento, status de pagamento, data de fechamento e contrato/observações. Porém, esses campos não são obrigatórios atualmente.

### 3. Pós-venda

A etapa `Vistoria` está configurada para enviar o projeto ao Pós-venda depois de 90 dias. O destino é `garantia fábrica`.

As etapas atuais do Pós-venda são:

1. garantia fábrica;
2. Manutenção Ativa;
3. Finalizados.

O Pós-venda acontece depois da venda, do contrato e normalmente depois do início dos recebimentos. Portanto, ele não deve ser o gatilho inicial do Financeiro.

## Situação dos dados atuais

No momento da análise:

- 113 leads ativos estavam em uma etapa ganha/conversão;
- somente 8 desses leads possuíam projeto vinculado;
- existiam 11 projetos no total;
- 5 projetos tinham valor maior que zero;
- 3 tinham forma de pagamento;
- 3 tinham status de pagamento;
- somente 1 tinha data de fechamento;
- nenhum tinha o campo contrato preenchido;
- 9 já estavam vinculados ao Pós-venda.

Isso demonstra que projeto criado, pagamento preenchido ou entrada no Pós-venda, isoladamente, não cobrem todas as vendas concluídas.

## Gatilho recomendado

O registro deve aparecer no Financeiro assim que o lead entrar em uma etapa configurada como ganha:

```text
is_conversion = 1 OR final_type = 'won'
```

Nesse momento ele entra na área **Entradas do CRM**, com situação **Aguardando conferência**, e ainda não gera parcelas automaticamente.

Essa entrada será um pré-cadastro financeiro, contendo o que já estiver disponível:

- lead e cliente;
- CPF/CNPJ;
- telefone e e-mail;
- cidade;
- vendedor/responsável;
- valor negociado;
- estimativa do projeto;
- forma de pagamento do lead;
- data em que a venda foi concluída.

## Evolução do registro no Financeiro

```text
Lead em VENDA CONCLUÍDA
        ↓
Entrada do CRM — Aguardando conferência
        ↓
Projeto criado/vinculado — dados complementados automaticamente
        ↓
Financeiro confere negociação e cria plano de pagamento
        ↓
Conta a receber ativa + parcelas/boletos
        ↓
Recebimentos parciais
        ↓
Quitado, inadimplente, renegociado ou cancelado
```

### Estados propostos para a caixa de entrada

- `Aguardando projeto`: venda concluída, mas sem projeto vinculado.
- `Dados incompletos`: projeto existe, mas faltam valor ou condições de pagamento.
- `Aguardando conferência`: dados suficientes para revisão do Financeiro.
- `Ativado`: plano de pagamento confirmado e parcelas geradas.
- `Cancelado/em revisão`: lead voltou de uma etapa ganha ou a venda foi desfeita.

## Regras importantes

1. O gatilho deve fazer `upsert`, com uma chave única por lead, para nunca duplicar a mesma venda.
2. Se o projeto for criado depois, o pré-cadastro financeiro existente deve ser atualizado e vinculado, não duplicado.
3. Um projeto criado diretamente, sem lead, também deve poder entrar no Financeiro quando for marcado como venda confirmada.
4. Voltar o lead de `VENDA CONCLUÍDA` não deve apagar dados financeiros. O registro deve ir para revisão/cancelamento, preservando o histórico.
5. A criação de parcelas exige confirmação humana, pois o CRM atual não armazena quantidade, vencimentos, entrada, juros e divisão completa do pagamento.
6. Depois da ativação, valores financeiros confirmados não devem ser sobrescritos silenciosamente por alterações no CRM.
7. O Financeiro deve ser a fonte de verdade do saldo. Quando o saldo chegar a zero, ele pode atualizar `projetos.payment_status` para `Pago`.
8. A entrada no Pós-venda deve apenas acrescentar marcos operacionais; não deve criar novamente a venda financeira.

## Importação do legado

Os Excel/CSV antigos devem entrar pela mesma caixa de conferência, identificados como `Origem: Importação`. Eles não devem disparar automaticamente parcelas ou misturar dados fictícios com clientes reais antes da confirmação.

## Conclusão

O melhor momento para o cliente aparecer no Financeiro é a conversão do lead em venda concluída. Ele aparece primeiro como pré-cadastro para conferência. O projeto e o Pós-venda enriquecem esse mesmo registro ao longo do ciclo, enquanto a geração de contas e parcelas ocorre somente após validação do Financeiro.
