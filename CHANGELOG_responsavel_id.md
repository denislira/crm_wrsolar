# Alterações realizadas - Salvar ID do responsável em Nova Tarefa

## Resumo
Implementado o armazenamento do ID do responsável (user_id) nas tarefas de equipe. Anteriormente, apenas o nome (username) era armazenado. Agora o sistema também mantém referência ao ID do usuário para melhor integridade referencial.

## Arquivos modificados:

### 1. **database/add_responsavel_id_to_team_tasks.sql** (NOVO)
- Migration SQL que adiciona:
  - Coluna `responsavel_id` (INT, NULL) à tabela `team_tasks`
  - Foreign key referenciando `users(id)` com ON DELETE SET NULL
- Será aplicada automaticamente ao executar `scripts/apply_migrations.php`

### 2. **includes/team_tasks_api.php**
Atualizações:
- **Caso 'add'**: 
  - Agora salva `responsavel_id` junto com o `responsavel` (username)
  - Se `responsavel_id` não for fornecido, usa o ID do usuário atual
  - Valida se o ID fornecido existe na tabela `users`
  
- **Caso 'update'**: 
  - Atualiza `responsavel_id` quando o responsável é alterado
  - Mantém sincronização entre `responsavel` (username) e `responsavel_id`

### 3. **integracao-equipes.php**
Atualizações HTML/PHP:
- **Modal Nova Tarefa** (formModalNovaTarefa):
  - Select `new-responsavel` agora possui atributo `data-user-id` para cada opção
  - Adicionado campo hidden `new-responsavel-id` 
  - JavaScript atualiza o campo hidden ao mudar a seleção
  
- **Quick Form** (formNovaTarefa):
  - Select `quick-new-responsavel` com atributo `data-user-id` para cada opção
  - Adicionado campo hidden `quick-new-responsavel-id`
  - JavaScript atualiza o campo hidden ao mudar a seleção

- **Modal Editar Tarefa** (formEditarTarefa):
  - Select `edit-responsavel` com atributo `data-user-id` para cada opção
  - Adicionado campo hidden `edit-responsavel-id-hidden`
  - JavaScript atualiza o campo hidden ao mudar a seleção

Atualizações JavaScript:
- **openEditModal()**: 
  - Inicializa `edit-responsavel-id-hidden` com o `responsavel_id` da tarefa
  
- **btnNovaTarefa click handler**: 
  - Inicializa `new-responsavel-id` quando o modal é aberto
  
- **formNovaTarefa submit handler**: 
  - Captura e envia o `responsavel_id` junto com os dados da tarefa
  
- **formModalNovaTarefa submit handler** (btnSalvarNovaModal):
  - O campo hidden já está no formulário, automaticamente capturado via FormData

## Fluxo de funcionamento:

1. **Criação de tarefa** (Modal ou Quick Form):
   - Usuário seleciona responsável no dropdown
   - JavaScript atualiza campo hidden com o `user_id` do responsável
   - Ao salvar, API recebe tanto `responsavel` (username) quanto `responsavel_id`
   - Tarefa é salva com ambos os campos preenchidos

2. **Edição de tarefa**:
   - Modal abre com tarefa carregada
   - Campo hidden é preenchido com `responsavel_id` da tarefa
   - Usuário pode mudar responsável
   - Se mudar, campo hidden é atualizado com novo ID
   - Ao salvar, ambos `responsavel` e `responsavel_id` são atualizados

3. **Banco de dados**:
   - `team_tasks` agora tem coluna `responsavel_id` com foreign key
   - Garante integridade referencial
   - Permite queries mais eficientes usando ID direto

## Como aplicar as mudanças:

### Opção 1: Via script de migrations (recomendado)
```bash
cd c:\xampp\htdocs\WRCRM
php scripts/apply_migrations.php
```

### Opção 2: Manualmente via MySQL
```sql
ALTER TABLE team_tasks ADD COLUMN responsavel_id INT DEFAULT NULL;
ALTER TABLE team_tasks ADD FOREIGN KEY (responsavel_id) REFERENCES users(id) ON DELETE SET NULL;
```

## Compatibilidade:
- ✅ Tarefas existentes continuam funcionando (responsavel_id será NULL)
- ✅ Novas tarefas já salvam o ID
- ✅ Ao editar tarefas antigas, o sistema preencherá o responsavel_id
- ✅ Backward compatible com código existente

## Notas técnicas:
- Campo `responsavel` (username) mantido para compatibilidade e melhor legibilidade
- Campo `responsavel_id` adicionado para integridade referencial e queries eficientes
- API valida se o ID fornecido existe antes de salvar
- Sincronização automática via JavaScript entre select e campo hidden
