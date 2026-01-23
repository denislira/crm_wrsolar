Exemplo `wa-service` — Baileys (WhatsApp)

Resumo
- Serviço Node.js que usa Baileys para autenticar um cliente WhatsApp.
- Quando Baileys gera um QR, o serviço grava o texto do QR em `../storage/wa_state.json` para que o painel PHP exiba o QR.
- Ao conectar, grava `{ connected: true, info: ... }` no mesmo arquivo.

Pré-requisitos
- Node.js 16+ instalado
- Executar a partir do servidor onde está o projeto WRCRM (para poder gravar em `../storage/wa_state.json`) ou setar `STORAGE_PATH` apontando para o arquivo de armazenamento do projeto.

Instalação

Windows (cmd):

```cmd
cd C:\xampp\htdocs\WRCRM\wa-service
npm install
```

Execução

```cmd
# rodar com caminho default (escreverá ../storage/wa_state.json)
node index.js

# ou indicar STORAGE_PATH explicitamente
set STORAGE_PATH=C:\xampp\htdocs\WRCRM\storage\wa_state.json
node index.js
```

Uso
- Ao iniciar, o serviço imprimirá o QR no terminal e também irá gravar o QR em `storage/wa_state.json`.
- Escaneie o QR usando o app do WhatsApp para autenticar.
- Para enviar mensagem manual via CLI, digite: `5511999999999|Olá` e pressione Enter.

Observações
- Este é um exemplo simples. Em produção, avalie proteção do arquivo de estado, rotinas de reconexão e segurança.
- A versão do Baileys usada é a declarada em `package.json`. Ajuste conforme necessário.
