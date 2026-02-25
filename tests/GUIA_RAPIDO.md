# GUIA RÁPIDO: Excluir Leads Permanentemente - Configuração Inicial

## 🎯 O que foi implementado?

Você agora pode **controlar quem tem permissão para excluir leads permanentemente** da lixeira, em vez de apenas deixar isso disponível para o admin.

**Antes:** Usuário comum recebe erro: *"Lead not found or insufficient permissions to delete permanently"*

**Agora:** Admin pode habilitar essa permissão por papel em Configurações

---

## ⚡ INÍCIO RÁPIDO (3 MINUTOS)

### 1️⃣ Aplicar a Mudança do Banco de Dados

Escolha A ou B:

#### **Opção A: Via URL (mais fácil)**
- Abra seu navegador e acesse:
  ```
  http://localhost/WRCRM/scripts/init_delete_leads_permanent_permission.php
  ```
  (substitua `localhost` pelo seu domínio)

- Você deve ver uma mensagem verde: ✓ Sucesso

- Pronto! A permissão foi adicionada ✅

#### **Opção B: Via Command Line**
```bash
mysql -u root -p crmwrsolare < database/add_delete_leads_permanent_permission.sql
```

### 2️⃣ Configurar os Papéis

1. Faça login como **Diretor** (admin)
2. Vá para **Configurações** > **Permissões**
3. No menu "Papel:", selecione um papel (ex: Gerente)
4. Procure na tabela por: **"Excluir leads permanentemente"** 
   - Tem um badge azul "Ação" do lado
5. Marque a caixa ✓ se quer habilitar para esse papel
6. Clique em **Salvar alterações**

**Default (depois de aplicar a migração):**
- ✓ Diretor: Habilitado
- ✗ Gerente: Desabilitado  
- ✗ Supervisor: Desabilitado
- ✗ Consultor: Desabilitado

### 3️⃣ Testar

1. Faça logout
2. Faça login com um usuário **Gerente** (ou novo papel que habilitou)
3. Vá para **Gestão de Leads** > **Lixeira**
4. Tente excluir um lead
5. ✓ Deve funcionar!

---

## 📚 Arquivos de Referência

| Arquivo | Uso |
|---------|-----|
| `DELETE_LEADS_PERMANENT_PERMISSION.md` | Documentação técnica completa |
| `IMPLEMENTACAO_RESUMO.md` | Detalhes técnicos da implementação |
| `database/add_delete_leads_permanent_permission.sql` | SQL da migração (se quiser aplicar manualmente) |

---

## ❓ FAQ

### P: Só aparecem 1-2 opções na lista de permissões, não vejo "Excluir leads"
**R:** Você não aplicou a migração do banco. Siga o Passo 1 acima.

### P: Apliquei a migração mas a permissão não aparece
**R:** Faça logout e login novamente (as permissões são carregadas na sessão).

### P: Um usuário tem a permissão mas não consegue deletar o lead de outro usuário
**R:** Isso é correto! Usuários comuns só deletam seus próprios leads.  
Apenas o Diretor (admin) pode deletar leads de qualquer pessoa.

### P: Como faço para um Consultor deletar leads?
**R:** 
1. Vá em Configurações > Permissões
2. Selecione "Consultor"
3. Marque "Excluir leads permanentemente da lixeira"
4. Clique Salvar
5. Pronto! Consultores podem agora deletar seus próprios leads.

### P: Posso voltar atrás (desabilitar para todos)?
**R:** Sim! Desmarque a caixa para qualquer papel e clique Salvar.

---

## 🔒 Notas de Segurança

- Somente o **Diretor** vê e edita as permissões
- Usuários **comuns só podem deletar seus próprios leads**, mesmo com permissão
- O **Diretor sempre pode deletar qualquer lead**
- Não há limite de categorias/papéis - funciona com qualquer papel criado

---

## 🆘 Não funciona? Checklist:

- [ ] Aplicou a migração? (Passo 1)
- [ ] Fez logout e login depois de aplicar? 
- [ ] Verificou em Configurações > Permissões? (deve aparecer "Excluir leads permanentemente")
- [ ] Marcou a caixa para o papel correto?
- [ ] Clicou em "Salvar alterações"?
- [ ] O usuário tem o papel correto? (Configurações > Gerenciar Usuários)

Se ainda não funcionar:
1. Verifique os logs em: `logs/leads_api_errors.log`
2. Consulte `DELETE_LEADS_PERMANENT_PERMISSION.md` para troubleshooting

---

## 📞 Suporte Técnico

- Documentação completa: `DELETE_LEADS_PERMANENT_PERMISSION.md`
- Sumário técnico: `IMPLEMENTACAO_RESUMO.md`
- Verificação: `scripts/verify_implementation.sh`

**Pronto para começar?** Siga os 3 passos acima! ⚡
