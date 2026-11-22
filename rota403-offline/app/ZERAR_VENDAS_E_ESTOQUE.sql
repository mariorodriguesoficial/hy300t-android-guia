-- Rota 403: apaga TODAS as vendas e zera o estoque.
-- Mantém produtos, categorias, preços, usuários, formas de pagamento e configurações.
-- FAÇA UMA CÓPIA DE SEGURANÇA DO BANCO ANTES DE RODAR.
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `comanda_pagamentos`;
DELETE FROM `comanda_itens`;
DELETE FROM `pedidos`;
DELETE FROM `comandas`;
DELETE FROM `caixas`;
SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fila_impressao');
SET @s := IF(@t > 0, 'DELETE FROM `fila_impressao`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
ALTER TABLE `comanda_pagamentos` AUTO_INCREMENT = 1;
ALTER TABLE `comanda_itens` AUTO_INCREMENT = 1;
ALTER TABLE `pedidos` AUTO_INCREMENT = 1;
ALTER TABLE `comandas` AUTO_INCREMENT = 1;
ALTER TABLE `caixas` AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;
UPDATE `produtos` SET `estoque` = 0;
