# Laderhændelser fra Home Assistant

HA sender fire hændelser til SparkSpend, hver gang laderen skifter tilstand, sammen med
et øjebliksbillede af bilerne (SoC, kilometerstand, kabelsensor og bilens eget tidsstempel).
Sessioner bygges af hændelserne i `includes/ChargerSessions.php` og kan ses på
`api.php?action=charger-sessions`.

| Hændelse | Udløses når | Bruges til |
|---|---|---|
| `plugged` | status går fra `disconnected` til en gyldig tilstand | Tilslutningstidspunkt, bilernes SoC ved ankomst |
| `charge_start` | status bliver `charging` | Start på selve ladningen (kan ligge timer efter tilslutning, når Monta venter på billig strøm) |
| `charge_end` | `session_energy` nulstilles til 0 | **Sessionens endelige energi** (værdien lige før nulstillingen) |
| `unplugged` | status bliver `disconnected` | Frakobling, bilernes SoC |

## Før du slår det til

1. **Kør migrationen**: `php migrate.php` (eller vent på auto-migrationen, der kører højst én gang i timen).
   Den opretter tabellen `charger_events`.
2. **Undtag `/receive_charger_event.php` fra Authentik** på samme måde som `/receive_vehicle_data.php`.
   Ellers bliver HA's kald omdirigeret til login, og HA melder "Client error".
3. Endpointet bruger **samme `VEHICLE_API_KEY`** som `receive_vehicle_data.php`.

## Hvorfor reglerne ser ud, som de gør (målt på rigtige data, 26.-30. okt. 2026)

- Det endelige `session_energy` kommer først **4-64 sekunder efter** `completed` og nulstilles straks. Læses sensoren på
  `completed`, er den 0,15-0,77 kWh bagud. Derfor udløses `charge_end` af nulstillingen, og energien er `from_state`.
- Alle laderens sensorer blinker til `unknown` / `unknown 0` i omkring 0,1 s ved genindlæsning eller genstart af HA.
  Reglerne kræver derfor en gyldig tilstand før og efter, ellers ville en genstart se ud som tilslutning og frakobling.
- Efter en genindlæsning viser `session_energy` den *forrige* sessions værdi i stedet for 0. SparkSpend bruger kun
  aflæsningen ved frakobling, hvis der har været ladning i sessionen, og værdien ikke bare gentager forrige session.
- `lifetime_energy` er den samme tæller, men opdateres kun cirka hver time, så den bruges kun som kontrol.

## Konfiguration

`configuration.yaml`:

```yaml
rest_command:
  sparkspend_charger_event:
    url: "https://sparkspend.useful.dk/receive_charger_event.php"
    method: post
    content_type: "application/json"
    headers:
      Authorization: !secret sparkspend_bearer      # "Bearer <VEHICLE_API_KEY>" (som til cablepluggedin)
    timeout: 15
    payload: >-
      {{ {
        'event': event,
        'eventAt': eventAt,
        'status': status,
        'sessionEnergy': sessionEnergy,
        'lifetimeEnergy': lifetimeEnergy,
        'power': power,
        'cars': cars
      } | tojson }}
```

Automation (tilpas entitetsnavnene; `'2'` er Enyaq og `'3'` er e-Up, som i `vehicles`-tabellen):

```yaml
alias: SparkSpend - laderhændelser
description: Sender plug-in, ladestart, ladeslut og frakobling med bilernes tilstand til SparkSpend
mode: queued
max: 20
triggers:
  - trigger: state
    entity_id: sensor.ehygy68p_status
    id: status
  - trigger: state
    entity_id: sensor.ehygy68p_session_energy
    id: session
variables:
  bad: ["unknown", "unavailable", "unknown 0", "none", ""]
  frm: "{{ trigger.from_state.state if trigger.from_state is not none else '' }}"
  to: "{{ trigger.to_state.state if trigger.to_state is not none else '' }}"
  event: >-
    {{ 'plugged' if (trigger.id == 'status' and frm == 'disconnected' and to not in bad and to != 'disconnected')
       else 'charge_start' if (trigger.id == 'status' and to == 'charging' and frm not in bad and frm != 'charging')
       else 'unplugged' if (trigger.id == 'status' and to == 'disconnected' and frm not in bad and frm != 'disconnected')
       else 'charge_end' if (trigger.id == 'session' and (frm | float(-1)) > 0 and (to | float(-1)) == 0)
       else '' }}
actions:
  - condition: template
    value_template: "{{ event != '' }}"
  - action: rest_command.sparkspend_charger_event
    continue_on_error: true
    response_variable: rest_result
    data:
      event: "{{ event }}"
      eventAt: "{{ trigger.to_state.last_changed.isoformat() }}"
      status: "{{ states('sensor.ehygy68p_status') }}"
      sessionEnergy: >-
        {{ (frm | float(none)) if event == 'charge_end' else (states('sensor.ehygy68p_session_energy') | float(none)) }}
      lifetimeEnergy: "{{ states('sensor.ehygy68p_lifetime_energy') | float(none) }}"
      power: "{{ states('sensor.ehygy68p_power') | float(none) }}"
      cars:
        '2':
          soc: "{{ states('sensor.skoda_enyaq_batteriprocent') | float(none) }}"
          odometer: "{{ states('sensor.skoda_enyaq_kilometerstand') | float(none) }}"
          cable: "{{ states('binary_sensor.skoda_enyaq_ladekabel') }}"
          carTs: "{{ states('sensor.skoda_enyaq_sidst_opdateret') }}"
        '3':
          soc: "{{ states('sensor.indkorsel_e_up_battery') | float(none) }}"
          odometer: "{{ states('sensor.indkorsel_e_up_mileage') | float(none) }}"
          cable: "{{ states('sensor.indkorsel_e_up_plug_state') }}"
          carTs: "{{ states('sensor.indkorsel_e_up_last_connected') }}"
  - if:
      - condition: template
        value_template: "{{ rest_result is not defined or rest_result.status not in [200, 201] }}"
    then:
      - action: persistent_notification.create
        data:
          title: SparkSpend - laderhændelse fejlede
          message: >-
            {{ event }} ({{ trigger.to_state.last_changed.isoformat() }}):
            {{ rest_result.status if rest_result is defined else 'intet svar' }}
```

Kaldet er idempotent: samme hændelse to gange gemmes kun én gang (svar 200), så et gentaget kald er ufarligt.

> HA-konfigurationen er skrevet ud fra HA's dokumenterede skabelonsyntaks, men er ikke kørt mod en rigtig HA.
> Test med Udviklerværktøjer -> Handlinger og kontrollér på `api.php?action=charger-sessions`.

## Test

```bash
curl -i -X POST https://sparkspend.useful.dk/receive_charger_event.php \
  -H "Authorization: Bearer <VEHICLE_API_KEY>" -H "Content-Type: application/json" \
  -d '{"event":"plugged","eventAt":"2026-10-31T18:00:00Z","status":"awaiting_authorization"}'
```

`201` = gemt, `200` = dublet, `401` = forkert nøgle, `400` = fejlen står i svaret. Svarer HA med 400 "Invalid JSON format",
er `payload` ikke blevet til JSON: kontrollér, at `tojson`-linjen står i `rest_command`.
