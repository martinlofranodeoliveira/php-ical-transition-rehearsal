# php-ical-transition-rehearsal

[![CI](https://github.com/martinlofranodeoliveira/php-ical-transition-rehearsal/actions/workflows/ci.yml/badge.svg)](https://github.com/martinlofranodeoliveira/php-ical-transition-rehearsal/actions/workflows/ci.yml)
[![Licença: MIT](https://img.shields.io/badge/Licen%C3%A7a-MIT-blue.svg)](LICENSE)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://www.php.net/)

Motor determinístico e seguro em PHP 8.4 com Sabre VObject para ensaio de recorrência em iCalendar (RFC 5545) e auditoria de transições de fuso horário e horário de verão (DST).

Disponível também em [Inglês](README.md).

## Funcionalidades

- **Iteração Nominal Desacoplada**: Utiliza âncora UTC com `RRuleIterator` do Sabre VObject para avançar rótulos civis nominais sem desvios precoces de horário de verão.
- **Resolução de Multiplicidade Física**: Resolve cada ocorrência nominal contra as transições nativas de `DateTimeZone` do PHP para identificar os instantes UTC exatos.
- **Políticas Declarativas de Lacunas e Dobras**:
  - **Lacunas de Primavera (0 candidatos)**: Opção entre `reject-series` (rejeição estrita da série) e `skip-instance` (omitir o horário inexistente com status `SKIPPED`).
  - **Dobras de Outono (2 candidatos)**: Resolução determinística entre `earlier-instant` (instante anterior) e `later-instant` (instante posterior).
- **Transições Não Horárias**: Suporte completo a desvios de 30 minutos (ex.: `Australia/Lord_Howe` $+11:00 \leftrightarrow +10:30$) e transições históricas (ex.: Brasil 2018).
- **Perfil Bounded Restrito**: Limita o payload a 64 KiB, COUNT a 100 e durações a 7 dias; rejeita extensões não conformes (`UNTIL`, `RDATE`, horário flutuante, múltiplas `VEVENT`).
- **Verificação via Oráculo**: Auditoria integrada contra o utilitário de sistema `zdump` e transições do banco `tzdata`.
- **Determinístico e Offline**: Zero dependências externas não homologadas; verificado para execução em ambientes estritamente isolados (`--network none`).

## Guia Rápido

### Executar Testes e Afirmações Offline

```bash
# Executar a suíte de testes offline
./scripts/test-offline.sh

# Executar afirmações analíticas diretamente
./scripts/demo-offline.sh --assert

# Executar afirmações com verificação do oráculo zdump
./scripts/demo-offline.sh --assert --oracle
```

### Interface de Linha de Comando (CLI)

```bash
# Avaliar arquivo iCalendar com saída formatada em JSON
php bin/ical-rehearsal.php --ics tests/fixtures/ny_autumn_fold.ics --json

# Resolver dobra de outono selecionando o instante posterior
php bin/ical-rehearsal.php --ics tests/fixtures/ny_autumn_fold.ics --fold-policy later-instant --json

# Tratar lacunas de primavera ignorando instantes inexistentes
php bin/ical-rehearsal.php --ics tests/fixtures/ny_spring_gap.ics --gap-policy skip-instance --json
```

## Uso Programático

```php
use Lofrano\IcalRehearsal\IcalRehearsal;

$rawIcs = file_get_contents('event.ics');

// Ensaio da série com rejeição estrita em lacunas de horário
$result = IcalRehearsal::rehearse(
    rawIcs: $rawIcs,
    gapPolicy: 'reject-series',
    foldPolicy: 'earlier-instant'
);

if ($result->success) {
    foreach ($result->occurrences as $occ) {
        echo "{$occ->nominalLabel} -> {$occ->selectedInstant} ({$occ->status})\n";
    }
} else {
    echo "Ensaio falhou: {$result->message}\n";
}
```

## Arquitetura e Especificações

Para contratos detalhados e especificações técnicas de fronteira:
- [Contrato e Limites de Recursos](docs/contract.md)
- [Design Arquitetural](docs/design.md)
- [Visão de Produto](PRODUCT.md)

## Licença

Este projeto é distribuído sob a [Licença MIT](LICENSE) © 2026 Lofrano Tecnologia.
