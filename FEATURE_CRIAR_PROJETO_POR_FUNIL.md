# Feature: Criar Projeto por Funil

## Descrição
Adicionada a funcionalidade de permitir criar projetos diretamente a partir de leads no Kanban, de forma seletiva por etapa do funil.

## Como Usar

### 1. Configurar a Etapa do Funil
1. Acesse **Gestão de Leads** → **Personalizar Funil**
2. Selecione a etapa desejada à esquerda
3. Na seção "Permitir criar projeto", marque o checkbox correspondente
4. Clique em **Salvar**

### 2. Usar o Botão de Criar Projeto
1. Na visualização Kanban, os leads que estão em etapas com a opção habilitada mostrarão um botão **"+ Criar Projeto"**
2. Clique no botão para abrir um diálogo
3. Digite o nome do projeto (por padrão, vem o nome do lead)
4. Confirme - o projeto será criado com status "Prospecção"

## Arquivos Modificados

### Backend
- **`includes/funil_stages_api.php`**
  - Adicionado suporte ao campo `allow_project_creation` nas ações LIST, ADD e UPDATE
  - A coluna é criada automaticamente se não existir

### Frontend
- **`funil_config.php`**
  - Adicionado checkbox "Permitir criar projeto" na edição de etapas
  
- **`assets/js/funil_config.js`**
  - Carrega o valor de `allow_project_creation` ao selecionar etapa
  - Salva o valor ao atualizar etapa

- **`assets/js/leads_gestao.js`**
  - Renderiza botão "Criar Projeto" nos cards quando a etapa tem permissão
  - Função `createProjectFromLead()` para executar a criação via API

## Banco de Dados
A coluna `allow_project_creation` é automaticamente criada na tabela `funil_stages` na primeira execução. Não é necessária migração manual.

## Fluxo de Funcionamento

```
Editar Funil (funil_config.php)
  ↓
Marcar "Permitir criar projeto" para uma etapa
  ↓
Salvar via API (funil_stages_api.php)
  ↓
Kanban carrega a configuração (leads_gestao.js - renderAll)
  ↓
Se allow_project_creation = 1, renderiza botão no card
  ↓
Clique no botão → prompt com nome do projeto
  ↓
POST para api/add_project.php
  ↓
Projeto criado com sucesso
```

## Observações
- O botão só aparece se a etapa está configurada com a opção habilitada
- O projeto é criado com status padrão "Prospecção"
- Cada funil pode ter múltiplas etapas com a opção habilitada
- A opção é independente para cada etapa
