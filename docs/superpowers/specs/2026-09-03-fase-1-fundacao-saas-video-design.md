# Fundação da plataforma SaaS de cortes com IA — Design da Fase 1

## Objetivo

Entregar a primeira versão executável em hospedagem compartilhada Hostinger, com landing page, autenticação segura, área autenticada e dashboard. A fundação deve aceitar evolução incremental para uploads, filas por banco, cron, FFmpeg, Gemini, clipes, créditos e administração sem exigir Node.js, Docker, Redis, WebSocket ou processos permanentes em produção.

## Escopo desta entrega

Esta fase inclui:

- estrutura organizada em PHP 8 com front controller;
- configuração por variáveis de ambiente;
- conexão MySQL 8 com PDO;
- migrations manuais e idempotentes;
- cadastro, login e logout;
- solicitação e redefinição de senha por token;
- proteção CSRF, sessão segura, validação e rate limiting básico;
- landing page responsiva com identidade visual própria;
- dashboard autenticado com dados reais do banco;
- páginas de perfil e configurações mínimas;
- tratamento uniforme de erros e logs sem dados sensíveis;
- documentação de instalação na Hostinger.

Upload, processamento de vídeo, Gemini, FFmpeg, MediaPipe, editor, monetização e painel administrativo ficam fora da implementação desta fase, mas seus limites arquiteturais serão preservados para as fases seguintes.

## Alternativas consideradas

### 1. Monólito modular em PHP puro — recomendado

Uma aplicação única, organizada por Controllers, Models, Services, Middleware e Views. Rotas web e endpoints JSON compartilham autenticação, autorização, banco e tratamento de erros. Tem implantação simples por upload/FTP ou Git, baixo consumo e nenhuma dependência de processo residente.

### 2. Framework PHP completo

Laravel ou Symfony acelerariam recursos comuns, mas aumentariam dependências, consumo, complexidade de implantação e exigências do ambiente. Continuam viáveis no futuro, porém não são a opção mais rápida e previsível para a restrição atual.

### 3. Aplicação fragmentada em serviços

Separar frontend, API e processador desde o início melhoraria escala independente, mas adicionaria autenticação distribuída, infraestrutura e operação incompatíveis com a prioridade de velocidade e hospedagem compartilhada.

## Arquitetura

O sistema será um monólito modular com `public/index.php` como ponto de entrada. O roteador transforma método e caminho em uma ação de controller. Middlewares aplicam sessão, CSRF, autenticação, autorização e rate limiting. Controllers coordenam casos de uso e entregam respostas HTML ou JSON. Models encapsulam persistência por PDO. Services concentram regras reutilizáveis, como autenticação, tokens, envio de e-mail, logs e, futuramente, Gemini e vídeo.

Pastas privadas (`app`, `config`, `database`, `storage`) ficam fora do acesso público sempre que a Hostinger permitir definir `public` como document root. Caso o plano exponha a raiz do projeto, regras de servidor negarão acesso direto às pastas privadas e somente `public` será navegável.

## Componentes

- `Core`: bootstrap, configuração, container simples, roteamento, request, response, sessão e view.
- `Middleware`: CSRF, autenticação, convidado, rate limit e cabeçalhos de segurança.
- `Controllers`: landing, autenticação, recuperação de senha e dashboard.
- `Models`: usuário, plano, projeto e transações de crédito, usando consultas preparadas.
- `Services`: autenticação, reset de senha, e-mail, logger e métricas do dashboard.
- `Views`: layouts separados para marketing, autenticação e aplicação.
- `public/assets`: CSS e JavaScript próprios, Bootstrap 5 e ícones carregados sem etapa de build.
- `database/migrations`: arquivos versionados executados apenas pelo comando manual.
- `storage/logs`: logs técnicos protegidos de acesso web.

## Modelo de dados inicial

### `migrations`

Registra nome e data de cada migration aplicada, garantindo execução única.

### `plans`

Contém nome, preço, minutos mensais, créditos, recursos em JSON e status. Recebe os planos Free, Pro e Business por seed controlado.

### `users`

Contém nome, e-mail único, hash de senha, avatar, plano, créditos, papel (`user` ou `admin`), status e timestamps.

### `password_reset_tokens`

Armazena somente o hash do token, usuário, expiração, consumo e timestamps. Tokens em texto puro existem apenas no link enviado.

### `rate_limits`

Mantém chave derivada, ação, janela, contador e expiração. Nenhuma senha ou token é registrado.

### `projects`

Será criada já com o estado mínimo previsto no briefing para que o dashboard mostre contagens reais e a próxima fase não exija alterar contratos.

### `credit_transactions`

Registra saldo por lançamento, tipo, quantidade, referência e descrição. O saldo exibido é consistente com o campo de créditos do usuário e será atualizado transacionalmente nas fases de processamento.

## Fluxos

### Cadastro

O usuário envia nome, e-mail, senha, confirmação e CSRF. O sistema valida, aplica rate limit, cria o usuário com `password_hash`, associa o plano Free, concede créditos iniciais por transação e inicia uma sessão com ID regenerado. E-mail duplicado retorna mensagem neutra e segura.

### Login

O sistema limita tentativas por combinação de IP derivado e e-mail normalizado, busca o usuário, verifica a senha e o status, regenera a sessão e redireciona ao dashboard. Falhas usam mensagem genérica.

### Recuperação de senha

O formulário sempre retorna a mesma confirmação. Quando o usuário existe, um token aleatório é gerado, somente seu hash é persistido e um link expirável é enviado. Em ambiente local, o transportador de desenvolvimento grava o link em log específico sem registrar senha nem chave. Na Hostinger, usa SMTP configurável.

### Dashboard

O middleware exige usuário autenticado. O serviço consulta projetos e créditos pertencentes ao usuário atual e mostra métricas reais, estado vazio e CTA para a futura criação de projeto. Nenhum dado de outro usuário é consultado.

## Interface

A landing adota fundo `#080808`, superfícies `#101010` e `#151515`, violeta `#7C3AED`/`#A855F7` e verde `#22C55E`. O visual combina tipografia forte, linhas sutis, brilhos controlados e uma demonstração abstrata da análise de vídeo, sem copiar referências. O dashboard utiliza sidebar recolhível, cabeçalho compacto, cartões de métricas, lista de projetos e estados vazios. No mobile, navegação vira drawer e os cartões passam para uma coluna.

Bootstrap 5 fornece grid e utilitários, mas componentes recebem estilo próprio para evitar aparência de template genérico. JavaScript puro gerencia menu, validação progressiva, feedback de formulários e interações, mantendo todas as regras críticas no servidor.

## Segurança

- consultas sempre preparadas;
- cookies de sessão `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS;
- regeneração de sessão após autenticação;
- CSRF em toda mutação;
- escape HTML centralizado;
- senhas com algoritmo padrão seguro do PHP;
- tokens aleatórios de alta entropia e persistidos apenas como hash;
- rate limiting de login, cadastro e recuperação;
- mensagens públicas sem stack trace ou caminhos internos;
- logs sanitizados;
- autorização por proprietário em toda consulta futura de recursos privados;
- cabeçalhos de segurança compatíveis com os assets usados.

## Erros e observabilidade

Exceções não tratadas recebem um identificador de correlação. Produção mostra mensagem amigável e grava contexto técnico sanitizado. Desenvolvimento pode habilitar detalhes por configuração. Falhas previsíveis de validação voltam ao formulário com campos preservados, exceto senhas.

## Testes e critérios de aceite

Uma suíte PHP sem serviços externos cobrirá roteamento, validação, CSRF, hash de senha, autenticação, autorização e rate limiting. Testes de integração que dependem de MySQL serão separados e documentados. Também serão executados lint PHP, validação da migration e smoke tests HTTP quando o ambiente disponibilizar PHP e banco.

A fase será aceita quando:

- a migration puder ser aplicada uma vez e reexecutada sem duplicação;
- cadastro, login, logout e reset seguirem os fluxos definidos;
- rotas privadas recusarem visitantes;
- o dashboard exibir dados reais do usuário autenticado;
- formulários rejeitarem CSRF inválido e entradas incorretas;
- erros não vazarem segredos;
- landing, autenticação e dashboard funcionarem em desktop e mobile;
- o README permitir instalar o sistema na Hostinger sem Node.js em produção.

## Evolução planejada

As próximas fases acrescentarão, em ordem: upload e biblioteca; jobs em MySQL e cron; FFmpeg; Gemini com validação de JSON e retry; geração de clipes; MediaPipe e contrato de detector substituível; legendas; editor; monetização; administração. Cada fase reutilizará autenticação, autorização, configuração, migrations, logs e os limites de serviço definidos aqui.
