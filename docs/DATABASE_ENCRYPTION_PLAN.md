# Planejamento para MySQL e PostgreSQL

> Estado: as etapas 1 a 3 foram implementadas. A matriz unitária dos dialetos foi
> adicionada; testes de integração com servidores reais e o comando de migração
> permanecem como próximas entregas.

## Objetivo

Permitir que o plugin escolha a implementação de criptografia a partir do driver da conexão CakePHP:

- MySQL: manter compatibilidade com `AES_ENCRYPT()` e `AES_DECRYPT()`.
- PostgreSQL: usar `pgcrypto`, preferencialmente `pgp_sym_encrypt()` e `pgp_sym_decrypt()`.

A API pública do behavior deve permanecer estável. Chamadas como `encrypt()`, `decryptField()`, filtros e ordenação não devem conhecer a sintaxe do banco.

## Decisão de arquitetura

Criar um contrato `DatabaseEncryptionDialect` com operações para:

1. validar chave, perfil, driver e recursos do servidor;
2. produzir a expressão de criptografia de um valor;
3. produzir a expressão de descriptografia de um campo;
4. informar o tipo de coluna e o tamanho mínimo recomendado;
5. expor um identificador de formato para migrações e diagnóstico.

Implementações iniciais:

- `MysqlEncryptionDialect`: usa `AES_ENCRYPT(valor, UNHEX(chave))` e `AES_DECRYPT(...)`, preservando os perfis legados já implantados.
- `PostgresPgcryptoDialect`: usa `pgp_sym_encrypt(valor, chave, opções)` e `pgp_sym_decrypt(...)`, com armazenamento em `bytea`.

Uma fábrica resolve o dialeto por `Connection::getDriver()`. Driver desconhecido deve produzir exceção clara durante a inicialização do behavior. A seleção automática pode ser substituída por configuração explícita para conexões decoradas ou drivers personalizados.

## Configuração proposta

```php
'CakeAes' => [
    'driver' => 'auto',
    'key' => env('CAKE_AES_KEY'),
    'mysql' => [
        'profile' => 'legacy',
        'expectedMode' => 'aes-128-ecb',
    ],
    'postgres' => [
        'cipher' => 'aes256',
        'compress-algo' => 0,
    ],
],
```

Durante a transição, `Security.key` continua aceito com aviso de descontinuação. A chave deve vir de segredo de ambiente, não do repositório. Os valores e a chave continuam como parâmetros vinculados.

## Formato e compatibilidade

Os formatos MySQL AES e OpenPGP do `pgcrypto` não são intercambiáveis. Uma aplicação que muda de banco não poderá copiar o conteúdo binário e continuar lendo-o diretamente.

Para tornar o formato identificável, uma versão posterior pode armazenar metadados em colunas auxiliares (`campo_crypto_driver`, `campo_crypto_version`) ou em um envelope binário. Isso não deve ser obrigatório na primeira entrega, para preservar esquemas atuais.

No PostgreSQL, usar `bytea`; no MySQL, `BLOB` ou `VARBINARY` dimensionado para o texto cifrado. O dialeto deve rejeitar colunas de texto para evitar conversões de charset nos bytes criptografados.

## Etapas de implementação

### 1. Extrair o dialeto MySQL

- Mover a construção das funções MySQL e a validação de perfil de `EncryptBehavior`/`EncryptionProfile` para `MysqlEncryptionDialect`.
- Manter o SQL e o formato atuais para que os registros existentes continuem legíveis.
- Preservar os testes de parâmetros vinculados, modo de sessão e chave.

Critério de aceite: a suíte MySQL atual passa sem alteração do conteúdo armazenado.

### 2. Implementar o dialeto PostgreSQL

- Detectar `Cake\\Database\\Driver\\Postgres`.
- Verificar `pg_extension` e falhar com mensagem de instalação quando `pgcrypto` não estiver habilitada.
- Produzir expressões CakePHP equivalentes a:

```sql
pgp_sym_encrypt(:value, :key, 'cipher-algo=aes256,compress-algo=0')
pgp_sym_decrypt("table"."field", :key)
```

- Validar opções por lista permitida; nunca interpolar configuração ou entrada livre em SQL.
- Definir retorno textual de `pgp_sym_decrypt()` e coluna `bytea` para o conteúdo cifrado.

Critério de aceite: salvar, obter, filtrar, ordenar e consultar associações funciona em PostgreSQL com acentos, barras, aspas, JSON, vazio e `NULL`.

### 3. Tornar o behavior independente do banco

- Resolver um dialeto por conexão durante `initialize()` ou de forma preguiçosa.
- Delegar `encrypt()` e `decryptField()` ao dialeto.
- Manter no behavior somente a integração com eventos, seleção, filtros, ordenação e associações.
- Invalidar/revalidar capacidades quando a conexão for restabelecida.

Critério de aceite: não existem nomes de funções MySQL ou PostgreSQL em `EncryptBehavior`.

### 4. Testes em matriz

- Testes unitários dos dialetos usando `ValueBinder`, garantindo que chave e valores não aparecem no SQL.
- Testes de integração em MySQL 8 e MariaDB para o formato legado.
- Testes de integração em PostgreSQL com `CREATE EXTENSION IF NOT EXISTS pgcrypto` executado pela preparação do ambiente, com usuário autorizado.
- Casos comuns para os dois bancos: CRUD, `NULL`, string vazia, Unicode, caracteres de escape, igualdade, `LIKE`, `IN`, ordenação, contain e reconexão.
- Teste negativo para extensão ausente, driver não suportado, modo MySQL incompatível e chave inválida.

Critério de aceite: a mesma suíte contratual roda para os dois dialetos; testes específicos cobrem diferenças do servidor.

### 5. Migração entre bancos ou formatos

Criar um comando CakePHP que processe lotes com checkpoint:

1. lê e descriptografa no banco de origem;
2. grava criptografado pelo dialeto de destino;
3. relê e compara o valor;
4. registra somente identificadores, contagens e erros, sem chave ou texto puro;
5. permite retomada e execução de verificação sem escrita.

A migração deve usar colunas/tabelas de destino separadas, backup testado e troca somente depois da conferência total. Não oferecer conversão SQL direta entre os formatos.

Critério de aceite: uma execução interrompida pode ser retomada sem duplicar ou perder registros, e a verificação final comprova igualdade dos textos em origem e destino.

## Entregas sugeridas

1. Refatoração interna e dialeto MySQL, sem mudança de formato.
2. Dialeto PostgreSQL e suíte de integração com `pgcrypto`.
3. Documentação de instalação, configuração e tipos de coluna.
4. Comando de migração e guia operacional.
5. Avaliação futura de um envelope autenticado e independente do banco para novas aplicações.

## Riscos a acompanhar

- Consultas sobre texto descriptografado impedem o uso normal de índices e podem expor texto no processo do banco.
- `LIKE` e ordenação exigem descriptografar linhas candidatas; precisam de testes de desempenho com volume real.
- O formato MySQL legado em ECB não oferece autenticação. O suporte preserva compatibilidade, mas não deve ser apresentado como formato recomendado para novos sistemas.
- `pgcrypto` executa criptografia no servidor; a chave chega ao banco como parâmetro e pode aparecer em logs mal configurados.
- Rotação de chave exige versão do formato e migração controlada; apenas trocar a configuração torna os dados existentes ilegíveis.
