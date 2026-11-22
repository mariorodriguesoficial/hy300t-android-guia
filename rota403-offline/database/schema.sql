-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Tempo de geração: 22/11/2026 às 12:53
-- Versão do servidor: 11.8.9-MariaDB-log
-- Versão do PHP: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Rota 403: esquema base (sem dados). Migrações em app/migrations/ completam.
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `caixas`
--

CREATE TABLE `caixas` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `saldo_inicial` decimal(10,2) NOT NULL DEFAULT 0.00,
  `saldo_final` decimal(10,2) DEFAULT NULL,
  `aberto_em` timestamp NULL DEFAULT current_timestamp(),
  `fechado_em` datetime DEFAULT NULL,
  `status` enum('aberto','fechado') NOT NULL DEFAULT 'aberto'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `caixas`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `categorias`
--

CREATE TABLE `categorias` (
  `id` int(11) NOT NULL,
  `nome` varchar(60) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `categorias`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `comandas`
--

CREATE TABLE `comandas` (
  `id` int(11) NOT NULL,
  `codigo` varchar(20) DEFAULT NULL,
  `identificacao` varchar(100) NOT NULL,
  `tipo` enum('mesa','cliente') NOT NULL DEFAULT 'mesa',
  `status` enum('aberta','fechada','cancelada') NOT NULL DEFAULT 'aberta',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `forma_pagamento` varchar(30) DEFAULT NULL,
  `caixa_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `fechado_em` datetime DEFAULT NULL,
  `impressa` tinyint(1) NOT NULL DEFAULT 1,
  `troco` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `comandas`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `comanda_itens`
--

CREATE TABLE `comanda_itens` (
  `id` int(11) NOT NULL,
  `comanda_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `quantidade` decimal(10,3) NOT NULL DEFAULT 1.000,
  `preco_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `garcom` varchar(100) DEFAULT NULL,
  `adicionado_em` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `comanda_itens`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `comanda_pagamentos`
--

CREATE TABLE `comanda_pagamentos` (
  `id` int(11) NOT NULL,
  `comanda_id` int(11) NOT NULL,
  `forma` varchar(40) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `recebido` decimal(10,2) NOT NULL DEFAULT 0.00,
  `criado_em` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `comanda_pagamentos`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `configuracoes`
--

CREATE TABLE `configuracoes` (
  `chave` varchar(50) NOT NULL,
  `valor` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `configuracoes`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `fila_impressao`
--

CREATE TABLE `fila_impressao` (
  `id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `ref_id` int(11) NOT NULL,
  `impresso` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `fila_impressao`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `formas_pagamento`
--

CREATE TABLE `formas_pagamento` (
  `id` int(11) NOT NULL,
  `nome` varchar(40) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 0,
  `criado_em` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `formas_pagamento`
--

INSERT INTO `formas_pagamento` (`id`, `nome`, `ordem`, `criado_em`) VALUES
(1, 'Dinheiro', 1, '2026-10-22 10:13:19'),
(2, 'PIX', 2, '2026-10-22 10:13:19'),
(3, 'Cartão de débito', 3, '2026-10-22 10:13:19'),
(4, 'Cartão de crédito', 4, '2026-10-22 10:13:19');

-- --------------------------------------------------------

--
-- Estrutura para tabela `login_tentativas`
--

CREATE TABLE `login_tentativas` (
  `id` int(11) NOT NULL,
  `login_hash` char(64) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `sucesso` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `login_tentativas`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `log_acesso`
--

CREATE TABLE `log_acesso` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `evento` varchar(40) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `log_acesso`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `pedidos`
--

CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL,
  `comanda_id` int(11) NOT NULL,
  `destino` varchar(30) NOT NULL DEFAULT 'cozinha',
  `itens` text NOT NULL,
  `garcom` varchar(100) DEFAULT NULL,
  `impresso` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `pedidos`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `preparos`
--

CREATE TABLE `preparos` (
  `slug` varchar(30) NOT NULL,
  `nome` varchar(40) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `preparos`
--

INSERT INTO `preparos` (`slug`, `nome`, `ordem`) VALUES
('balcao', 'BAR', 2),
('cozinha', 'COZINHA', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` int(11) NOT NULL,
  `codigo_barras` varchar(50) DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `preco` decimal(10,2) NOT NULL DEFAULT 0.00,
  `categoria_id` int(11) DEFAULT NULL,
  `estoque` decimal(10,3) NOT NULL DEFAULT 0.000,
  `controlar_estoque` tinyint(1) NOT NULL DEFAULT 1,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `imprimir_na_comanda` tinyint(1) NOT NULL DEFAULT 1,
  `destino` varchar(30) NOT NULL DEFAULT 'cozinha',
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `unidade` varchar(3) NOT NULL DEFAULT 'un'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `produtos`
--


-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(400) NOT NULL,
  `login` varchar(50) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `nivel` enum('admin','caixa','garcom') NOT NULL DEFAULT 'garcom',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `usuarios`
--


--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `caixas`
--
ALTER TABLE `caixas`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `comandas`
--
ALTER TABLE `comandas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`),
  ADD KEY `status` (`status`);

--
-- Índices de tabela `comanda_itens`
--
ALTER TABLE `comanda_itens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_item_cmd` (`comanda_id`),
  ADD KEY `fk_item_prod` (`produto_id`);

--
-- Índices de tabela `comanda_pagamentos`
--
ALTER TABLE `comanda_pagamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `comanda_id` (`comanda_id`);

--
-- Índices de tabela `fila_impressao`
--
ALTER TABLE `fila_impressao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pend` (`impresso`,`id`);

--
-- Índices de tabela `formas_pagamento`
--
ALTER TABLE `formas_pagamento`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Índices de tabela `login_tentativas`
--
ALTER TABLE `login_tentativas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login` (`login_hash`,`criado_em`),
  ADD KEY `idx_ip` (`ip`,`criado_em`);

--
-- Índices de tabela `log_acesso`
--
ALTER TABLE `log_acesso`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usuario` (`usuario_id`,`criado_em`);

--
-- Índices de tabela `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `impresso` (`impresso`,`destino`),
  ADD KEY `fk_ped_cmd` (`comanda_id`);

--
-- Índices de tabela `preparos`
--
ALTER TABLE `preparos`
  ADD PRIMARY KEY (`slug`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo_barras` (`codigo_barras`),
  ADD KEY `fk_prod_cat` (`categoria_id`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `caixas`
--
ALTER TABLE `caixas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `comandas`
--
ALTER TABLE `comandas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `comanda_itens`
--
ALTER TABLE `comanda_itens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `comanda_pagamentos`
--
ALTER TABLE `comanda_pagamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `fila_impressao`
--
ALTER TABLE `fila_impressao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `formas_pagamento`
--
ALTER TABLE `formas_pagamento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `login_tentativas`
--
ALTER TABLE `login_tentativas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `log_acesso`
--
ALTER TABLE `log_acesso`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `comanda_itens`
--
ALTER TABLE `comanda_itens`
  ADD CONSTRAINT `fk_item_cmd` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_prod` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`);

--
-- Restrições para tabelas `pedidos`
--
ALTER TABLE `pedidos`
  ADD CONSTRAINT `fk_ped_cmd` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `produtos`
--
ALTER TABLE `produtos`
  ADD CONSTRAINT `fk_prod_cat` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
