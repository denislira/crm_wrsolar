-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Tempo de geração: 11/07/2026 às 03:42
-- Versão do servidor: 8.4.8
-- Versão do PHP: 8.3.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `adonify`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `aceites_legais`
--

CREATE TABLE `aceites_legais` (
  `id` bigint UNSIGNED NOT NULL,
  `cliente_id` bigint UNSIGNED NOT NULL,
  `documento_id` bigint UNSIGNED NOT NULL,
  `tipo_documento` enum('termos_uso','politica_privacidade','politica_cancelamento','contrato_assinatura') COLLATE utf8mb4_unicode_ci NOT NULL,
  `versao_documento` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aceito_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `assinaturas`
--

CREATE TABLE `assinaturas` (
  `id` bigint UNSIGNED NOT NULL,
  `cliente_id` bigint UNSIGNED NOT NULL,
  `produto_id` bigint UNSIGNED NOT NULL,
  `plano_id` bigint UNSIGNED NOT NULL,
  `id_cliente_externo` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_assinatura_externa` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pendente','ativa','atrasada','cancelada','teste') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `iniciado_em` timestamp NULL DEFAULT NULL,
  `expira_em` timestamp NULL DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id` bigint UNSIGNED NOT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nome_empresa` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_id` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verificado` tinyint(1) NOT NULL DEFAULT '0',
  `provedor_auth` enum('email','google') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `status` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `configuracoes_cobranca`
--

CREATE TABLE `configuracoes_cobranca` (
  `id` bigint UNSIGNED NOT NULL,
  `chave_configuracao` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor_configuracao` text COLLATE utf8mb4_unicode_ci,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `consentimentos_lgpd`
--

CREATE TABLE `consentimentos_lgpd` (
  `id` bigint UNSIGNED NOT NULL,
  `cliente_id` bigint UNSIGNED NOT NULL,
  `finalidade` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_legal` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consentiu` tinyint(1) NOT NULL DEFAULT '1',
  `origem` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cadastro',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revogado_em` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `documentos_legais`
--

CREATE TABLE `documentos_legais` (
  `id` bigint UNSIGNED NOT NULL,
  `tipo` enum('termos_uso','politica_privacidade','politica_cancelamento','contrato_assinatura') COLLATE utf8mb4_unicode_ci NOT NULL,
  `versao` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conteudo` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('rascunho','publicado','arquivado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'publicado',
  `publicado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `eventos_webhook`
--

CREATE TABLE `eventos_webhook` (
  `id` bigint UNSIGNED NOT NULL,
  `origem` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chave_evento` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_externo` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json NOT NULL,
  `processado_em` timestamp NULL DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pagamentos`
--

CREATE TABLE `pagamentos` (
  `id` bigint UNSIGNED NOT NULL,
  `assinatura_id` bigint UNSIGNED NOT NULL,
  `id_pagamento_externo` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pendente','confirmado','recebido','recusado','estornado','chargeback') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `data_vencimento` date DEFAULT NULL,
  `pago_em` timestamp NULL DEFAULT NULL,
  `payload_bruto` json DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `planos`
--

CREATE TABLE `planos` (
  `id` bigint UNSIGNED NOT NULL,
  `produto_id` bigint UNSIGNED NOT NULL,
  `nome` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preco` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ciclo_cobranca` enum('mensal','trimestral','semestral','anual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mensal',
  `max_usuarios` int DEFAULT NULL,
  `max_espacos_trabalho` int DEFAULT NULL,
  `destaque` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `ordem_exibicao` int NOT NULL DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` bigint UNSIGNED NOT NULL,
  `nome` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao_curta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_landing` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `ordem_exibicao` int NOT NULL DEFAULT '0',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `solicitacoes_lgpd`
--

CREATE TABLE `solicitacoes_lgpd` (
  `id` bigint UNSIGNED NOT NULL,
  `cliente_id` bigint UNSIGNED DEFAULT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` enum('acesso','correcao','exclusao','portabilidade','revogacao_consentimento','oposicao','informacao_compartilhamento','outro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensagem` text COLLATE utf8mb4_unicode_ci,
  `status` enum('aberta','em_analise','concluida','recusada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `respondido_em` timestamp NULL DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios_admin`
--

CREATE TABLE `usuarios_admin` (
  `id` bigint UNSIGNED NOT NULL,
  `nome` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `perfil` enum('superadmin','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'superadmin',
  `status` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `aceites_legais`
--
ALTER TABLE `aceites_legais`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_aceites_legais_documento` (`documento_id`),
  ADD KEY `idx_aceites_legais_cliente_tipo` (`cliente_id`,`tipo_documento`,`aceito_em`);

--
-- Índices de tabela `assinaturas`
--
ALTER TABLE `assinaturas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assinaturas_cliente_status` (`cliente_id`,`status`,`criado_em`),
  ADD KEY `fk_assinaturas_produto` (`produto_id`),
  ADD KEY `fk_assinaturas_plano` (`plano_id`);

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `google_id` (`google_id`);

--
-- Índices de tabela `configuracoes_cobranca`
--
ALTER TABLE `configuracoes_cobranca`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chave_configuracao` (`chave_configuracao`);

--
-- Índices de tabela `consentimentos_lgpd`
--
ALTER TABLE `consentimentos_lgpd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_consentimentos_lgpd_cliente_finalidade` (`cliente_id`,`finalidade`,`registrado_em`);

--
-- Índices de tabela `documentos_legais`
--
ALTER TABLE `documentos_legais`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_documentos_legais_tipo_versao` (`tipo`,`versao`),
  ADD KEY `idx_documentos_legais_tipo_status` (`tipo`,`status`,`publicado_em`);

--
-- Índices de tabela `eventos_webhook`
--
ALTER TABLE `eventos_webhook`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_eventos_webhook_origem_chave` (`origem`,`chave_evento`,`criado_em`);

--
-- Índices de tabela `pagamentos`
--
ALTER TABLE `pagamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pagamentos_assinatura_status` (`assinatura_id`,`status`,`criado_em`);

--
-- Índices de tabela `planos`
--
ALTER TABLE `planos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_planos_produto_slug` (`produto_id`,`slug`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Índices de tabela `solicitacoes_lgpd`
--
ALTER TABLE `solicitacoes_lgpd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_solicitacoes_lgpd_cliente` (`cliente_id`),
  ADD KEY `idx_solicitacoes_lgpd_email_status` (`email`,`status`,`criado_em`);

--
-- Índices de tabela `usuarios_admin`
--
ALTER TABLE `usuarios_admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `aceites_legais`
--
ALTER TABLE `aceites_legais`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `assinaturas`
--
ALTER TABLE `assinaturas`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `configuracoes_cobranca`
--
ALTER TABLE `configuracoes_cobranca`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `consentimentos_lgpd`
--
ALTER TABLE `consentimentos_lgpd`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `documentos_legais`
--
ALTER TABLE `documentos_legais`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `eventos_webhook`
--
ALTER TABLE `eventos_webhook`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `pagamentos`
--
ALTER TABLE `pagamentos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `planos`
--
ALTER TABLE `planos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `solicitacoes_lgpd`
--
ALTER TABLE `solicitacoes_lgpd`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `usuarios_admin`
--
ALTER TABLE `usuarios_admin`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `aceites_legais`
--
ALTER TABLE `aceites_legais`
  ADD CONSTRAINT `fk_aceites_legais_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_aceites_legais_documento` FOREIGN KEY (`documento_id`) REFERENCES `documentos_legais` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `assinaturas`
--
ALTER TABLE `assinaturas`
  ADD CONSTRAINT `fk_assinaturas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assinaturas_plano` FOREIGN KEY (`plano_id`) REFERENCES `planos` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assinaturas_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `consentimentos_lgpd`
--
ALTER TABLE `consentimentos_lgpd`
  ADD CONSTRAINT `fk_consentimentos_lgpd_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `pagamentos`
--
ALTER TABLE `pagamentos`
  ADD CONSTRAINT `fk_pagamentos_assinatura` FOREIGN KEY (`assinatura_id`) REFERENCES `assinaturas` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `planos`
--
ALTER TABLE `planos`
  ADD CONSTRAINT `fk_planos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `solicitacoes_lgpd`
--
ALTER TABLE `solicitacoes_lgpd`
  ADD CONSTRAINT `fk_solicitacoes_lgpd_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
