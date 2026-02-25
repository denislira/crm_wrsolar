#!/bin/bash
# Test script to verify delete_leads_permanent permission implementation
# Run this from the command line to verify all components are in place

echo "=== Verificando Implementação de Permissão: Excluir Leads Permanentemente ==="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if files exist
check_file() {
    if [ -f "$1" ]; then
        echo -e "${GREEN}✓${NC} Arquivo existe: $1"
        return 0
    else
        echo -e "${RED}✗${NC} Arquivo NÃO encontrado: $1"
        return 1
    fi
}

# Check if file contains text
check_content() {
    if grep -q "$2" "$1" 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Encontrado em $1: \"$2\""
        return 0
    else
        echo -e "${RED}✗${NC} NÃO encontrado em $1: \"$2\""
        return 1
    fi
}

echo "--- 1. Arquivos e Migrações ---"
check_file "database/add_delete_leads_permanent_permission.sql"
check_file "scripts/init_delete_leads_permanent_permission.php"
check_file "DELETE_LEADS_PERMANENT_PERMISSION.md"

echo ""
echo "--- 2. Modificações em Código Existente ---"
check_content "includes/leads_api.php" "require_once __DIR__ . '/permissions.php';"
check_content "includes/leads_api.php" "if (!hasPermission('delete_leads_permanent'))"
check_content "asset/js/leads_gestao.js" "data.error.includes('insufficient permissions')" || check_content "assets/js/leads_gestao.js" "insufficient permissions"
check_content "configuracoes.php" "delete_leads_permanent"

echo ""
echo "--- 3. Descrições de Permissões na UI ---"
check_content "configuracoes.php" "'delete_leads_permanent': 'Excluir leads permanentemente da lixeira'"

echo ""
echo "=== Checklist de Implementação ==="
echo ""
echo "Próximos Passos:"
echo "1. Aplicar a migração do banco de dados:"
echo "   a) Via script: mysql://localhost/crmwrsolare < database/add_delete_leads_permanent_permission.sql"
echo "   b) Ou acessar: http://localhost/WRCRM/scripts/init_delete_leads_permanent_permission.php"
echo ""
echo "2. Verificar no banco de dados se a permissão foi adicionada:"
echo "   SELECT COUNT(*) FROM role_permissions WHERE screen = 'delete_leads_permanent';"
echo ""
echo "3. Testar na interface:"
echo "   a) Acesse Configurações > Permissões"
echo "   b) A permissão 'Excluir leads permanentemente da lixeira' deve aparecer"
echo "   c) Por padrão, apenas 'Diretor' tem esta permissão habilitada"
echo ""
echo "4. Testar a funcionalidade:"
echo "   a) Como usuário comum, tente excluir um lead da lixeira"
echo "   b) Você deve ver a mensagem: 'Você não tem permissão para excluir leads permanentemente...'"
echo "   c) Como admin, habilite a permissão para esse usuário em Configurações > Permissões"
echo "   d) Agora o usuário conseguirá excluir leads"
echo ""
echo "=== FIM ==="
