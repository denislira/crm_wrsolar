# RESUMO DE IMPLEMENTAÇÃO: PERMISSÃO "EXCLUIR LEADS PERMANENTEMENTE"

## 🎯 Objetivo
Permitir que administradores controlem quale usuários podem excluir leads permanentemente da lixeira, resolvendo o erro: *"Lead not found or insufficient permissions to delete permanently"*

---

## 📋 MUDANÇAS IMPLEMENTADAS

### 1. **Nova Migração de Banco de Dados**
**Arquivo:** `database/add_delete_leads_permanent_permission.sql`
- Adiciona a permissão `delete_leads_permanent` para todos os papéis
- Estado inicial: Habilitado apenas para "Diretor" (role_id=1)
- Status para outros papéis: Desabilitado (pode ser ativado no admin)

**O que faz:**
```sql
-- Diretor: ✓ Habilitado
-- Gerente: ✗ Desabilitado
-- Supervisor: ✗ Desabilitado
-- Consultor: ✗ Desabilitado
```

### 2. **Modificações na API de Leads**
**Arquivo:** `includes/leads_api.php`

#### 2.1 Adicionado import de permissões (linha 49):
```php
require_once __DIR__ . '/permissions.php';
```

#### 2.2 Modificado a ação `delete_permanent` (linha 866-882):
```php
if ($action === 'delete_permanent') {
    if (empty($data['id'])) { throw new Exception('Missing id'); }
    // ✨ NOVO: Verificar permissão
    if (!hasPermission('delete_leads_permanent')) {
        throw new Exception('Lead not found or insufficient permissions to delete permanently');
    }
    // ... resto do código ...
}
```

**Resultado:**
- Usuários sem permissão recebem erro claro
- Admins continuam podendo deletar cualquier lead
- Usuários comum só deletam seus própios leads (com permissão)

### 3. **Melhorias na Interface/UI**
**Arquivo:** `configuracoes.php`

#### 3.1 Adicionadas descrições de permissões (linha 856-868):
```javascript
const permDescriptions = {
    'dashboard': 'Acessar Dashboard',
    // ... outras permissões ...
    'delete_leads_permanent': 'Excluir leads permanentemente da lixeira'
};
```

#### 3.2 Adicionado suporte visual para permissões de ação (linha 886-889):
```javascript
const isAction = s.includes('_') && !['leads_gestao','pos-venda','funil_config','integracao-equipes'].includes(s);
tdName.innerHTML = isAction ? 
    `<span class="badge bg-info me-2">Ação</span>${escapeHtml(label)}` 
    : escapeHtml(label);
```

**Resultado:**
- Permissões de "ação" aparecem com badge azul "Ação"
- Descrições amigáveis em português
- Busca funciona para nome e descrição

### 4. **Tratamento de Erros Melhorado**
**Arquivo:** `assets/js/leads_gestao.js`

#### 4.1 Função `deletePermanent` (linha 184-213):
```javascript
async function deletePermanent(id){
    if (!confirm('Excluir permanentemente este lead? Esta ação não pode ser desfeita.')) return;
    try {
        const formData = new FormData();
        formData.append('id', id);
        const res = await fetch(apiBase + '?action=delete_permanent', { 
            method: 'POST', 
            body: formData 
        });
        if (res.ok) {
            // ... sucesso ...
        } else {
            // ✨ NOVO: Parsear erro JSON e mostrar mensagem amigável
            let errorMsg = 'Erro ao excluir lead';
            try {
                const data = await res.json();
                if (data.error) {
                    errorMsg = data.error;
                    if (data.error.includes('insufficient permissions')) {
                        errorMsg = 'Você não tem permissão para excluir leads permanentemente. '
                                 + 'Peça ao administrador para autorizar esta ação em '
                                 + 'Configurações > Permissões.';
                    }
                }
            } catch (e) { }
            alert(errorMsg);
        }
    } catch (err) {
        console.error(err);
        alert('Erro ao excluir lead');
    }
}
```

**Resultado:**
- Mensagens de erro mais claras
- Instruções para o usuário sobre como pedir permissão
- Referência direta a onde solicitar

### 5. **Scripts Helper**
**Arquivo:** `scripts/init_delete_leads_permanent_permission.php`

- Script automático para inicializar a permissão
- Somente Diretor pode executar
- Mostra status de cada papel
- Instruções pós-instalação

### 6. **Documentação Completa**
**Arquivo:** `DELETE_LEADS_PERMANENT_PERMISSION.md`

- Explicação detalhada da solução
- Guia de instalação (3 opções)
- Como configurar as permissões
- FAQ e troubleshooting
- Padrão extensível para futuras permissões

---

## 🚀 COMO USAR

### Passo 1: Aplicar a Migração
Escolha uma opção:

**Opção A (Recomendada - Via Script):**
```
http://seu-servidor/WRCRM/scripts/init_delete_leads_permanent_permission.php
```

**Opção B (SQL direto):**
```sql
mysql -u usuario -p database_name < database/add_delete_leads_permanent_permission.sql
```

**Opção C (Copiar e colar):**
Executar o SQL do arquivo `database/add_delete_leads_permanent_permission.sql` direto no MySQL

### Passo 2: Configurar Permissões
1. Acesse **Configurações > Permissões**
2. Selecione um papel (ex: "Gerente")
3. Procure por "Excluir leads"
4. Marque ✓ se quiser habilitar para esse papel
5. Clique **Salvar alterações**

### Passo 3: Testar
1. Logine como usuário com nova permissão
2. Vá para 《Gestão de Leads > Lixeira》
3. Tente excluir um lead
4. Deve funcionar! ✓

---

## 📊 ESTRUTURA DO SISTEMA

```
┌─────────────────────────────────────────────────────────────┐
│                    USUÁRIO TENTA DELETAR                    │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
        ┌────────────────────────────┐
        │ API: delete_permanent      │
        │ (includes/leads_api.php)   │
        └────────────┬───────────────┘
                     │
         ✨ NOVO: Verificar permissão
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
    ✓ Tem permissão       ✗ Sem permissão
         │                     │
         ▼                     ▼
    Deletar lead      Retornar erro JSON
    com sucesso       (msg amigável)
         │                     │
         └───────────┬─────────┘
                     ▼
        ┌────────────────────────────┐
        │ Front-end mostra resultado │
        │ (assets/js/leads_gestao.js)│
        └────────────────────────────┘
```

---

## 🔍 VERIFICAÇÃO DA INSTALAÇÃO

### Via SQL:
```sql
-- Verificar se a permissão foi adicionada
SELECT role_id, screen, allowed 
FROM role_permissions 
WHERE screen = 'delete_leads_permanent'
ORDER BY role_id;

-- Resultado esperado:
-- role_id | screen                    | allowed
-- 1       | delete_leads_permanent   | 1
-- 2       | delete_leads_permanent   | 0
-- 3       | delete_leads_permanent   | 0
-- 4       | delete_leads_permanent   | 0
```

### Via Interface:
1. Acesse **Configurações > Permissões**
2. Selecione qualquer papel
3. Procure na lista por "Excluir leads permanentemente"
4. Deve aparecer com badge "Ação"

### Via Teste Funcionalmente:
1. Como usuário sem permissão, tente deletar um lead
2. Mensagem esperada: *"Você não tem permissão para excluir leads permanentemente..."*
3. Admin habilita a permissão
4. Usuário consegue deletar agora ✓

---

## 📝 ARQUIVOS MODIFICADOS

| Arquivo | Mudança |
|---------|---------|
| `includes/leads_api.php` | Adicionado: `require_once permissions.php` + Verificação de permissão |
| `configuracoes.php` | Melhorias de UI para exibir permissões de ação |
| `assets/js/leads_gestao.js` | Melhor tratamento de erros com mensagens amigáveis |

## 📁 ARQUIVOS ADICIONADOS

| Arquivo | Propósito |
|---------|-----------|
| `database/add_delete_leads_permanent_permission.sql` | Migração do banco de dados |
| `scripts/init_delete_leads_permanent_permission.php` | Script helper para inicializar |
| `DELETE_LEADS_PERMANENT_PERMISSION.md` | Documentação completa |
| `verify_implementation.sh` | Script de verificação |

---

## ✅ CHECKLIST DE VALIDAÇÃO

- [x] Migração de banco de dados criada
- [x] Verificação de permissão em leads_api.php
- [x] UI melhorada em configuracoes.php
- [x] Tratamento de erros em leads_gestao.js
- [x] Script helper para inicialização
- [x] Documentação completa
- [x] Compatível com sistema estensível de permissões
- [x] Sem quebra de backward compatibility

---

## 🔄 EXTENSIBILIDADE

Este padrão é totalmente extensível. Para adicionar uma nova permissão de ação:

1. **Banco de dados:**
   ```sql
   INSERT INTO role_permissions (role_id, screen, allowed) 
   VALUES (1, 'nova_acao', 1);
   ```

2. **Código:**
   ```php
   if (!hasPermission('nova_acao')) {
       throw new Exception('Acesso negado');
   }
   ```

3. **UI (automática):**
   - Adicionar descrição em `permDescriptions`
   - Sistema detecta automaticamente como "ação"

---

## 🆘 TROUBLESHOOTING

### Problema: Mensagem de erro genérica
**Solução:** Verifique que o banco foi atualizado:
```sql
SELECT COUNT(*) FROM role_permissions 
WHERE screen = 'delete_leads_permanent';
```

### Problema: Permissão aparece selecionada mas não funciona
**Solução:** Verifique que o usuário está fazendo login novamente (permissões são carregadas na sessão)

### Problema: Script retorna erro SQL
**Solução:** Verifique se a tabela `role_permissions` existe:
```sql
DESC role_permissions;
```

---

## 📞 SUPORTE

Para dúvidas ou problemas:
1. Consulte `DELETE_LEADS_PERMANENT_PERMISSION.md`
2. Verifique os logs em `logs/leads_api_errors.log`
3. Execute `verify_implementation.sh` para diagnóstico
