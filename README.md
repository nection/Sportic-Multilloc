# SportTic

Plugin de WordPress per gestionar la planificació de pavellons i piscines, incloent horaris per carril, bloquejos puntuals i excepcions recurrents. El connector crea les taules necessàries a l'activació (programació, bloquejos, excepcions i historial d'undo/redo) i manté la migració des d'opcions antigues per conservar dades existents.

## Funcionalitats
- **Gestió d'horaris**: Desa la programació de piscines en una taula dedicada amb índexos per optimitzar consultes per data i piscina.
- **Bloquejos i excepcions**: Inclou taules per a bloquejos de carrils i excepcions d'esdeveniments recurrents, per evitar conflictes d'agenda.
- **Historial d'edicions**: Manté taules d'undo/redo per desfer o refer canvis sobre la configuració guardada.
- **Shortcode públic**: El shortcode `sportic_team_schedule` mostra les sessions d'un equip, permet ocultar dies sense activitat i respecta els colors corporatius configurats.

## Instal·lació
1. Copia el contingut d'aquest repositori a un directori dins de `wp-content/plugins/` del teu WordPress.
2. Accedeix a l'administració de WordPress i activa el plugin **SportTic**. En activar-se, es crearan totes les taules necessàries i es migraran dades existents si cal.
3. Personalitza els ajustos i paletes de colors des del panell d'administració segons necessitats.

## Ús
- Afegeix el shortcode `sportic_team_schedule` en una pàgina o entrada per mostrar la graella setmanal d'un equip. Utilitza l'atribut `code` per identificar l'equip i `title` per definir el títol opcional.

## Estructura del projecte
- `SportTic.php`: Fitxer principal del plugin amb totes les funcionalitats, activació i shortcodes.
- `css/`: Estils per a la interfície pública i d'administració.
- `imatges/`: Recursos gràfics utilitzats pel plugin.
