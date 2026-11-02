# Knihovny třetích stran

Pevné verze, žádné CDN. Soubory jsou beze změn převzaté z balíčků npm.

| Knihovna | Verze | Soubor | Zdroj | Licence |
|---|---|---|---|---|
| Cytoscape.js | 3.34.2 | `cytoscape-3.34.2/cytoscape.min.js` | https://www.npmjs.com/package/cytoscape (`dist/cytoscape.min.js`) | MIT, `cytoscape-3.34.2/LICENSE` |
| dagre | 0.8.5 | `dagre-0.8.5/dagre.min.js` | https://www.npmjs.com/package/dagre (`dist/dagre.min.js`) | MIT, `dagre-0.8.5/LICENSE` |
| cytoscape-dagre | 2.5.0 | `cytoscape-dagre-2.5.0/cytoscape-dagre.js` | https://www.npmjs.com/package/cytoscape-dagre (`cytoscape-dagre.js`) | MIT, `cytoscape-dagre-2.5.0/LICENSE` |
| three.js | 0.170.0 | `three-0.170.0/three.module.min.js` | https://www.npmjs.com/package/three (`build/three.module.min.js`) | MIT, `three-0.170.0/LICENSE` |

Kontrolní součty SHA-256:

```
b85c213252b880cbb2d86c10dc537f673560e82494da4330f1ccc18fbcb5f145  cytoscape-3.34.2/cytoscape.min.js
62eb9787ccfdbdf4148d4d99d31dbf9ee4770eafee81e637d759b52aac22cd51  dagre-0.8.5/dagre.min.js
bf70fe402991dcbff33e05a7e4a5271c78020bb75e85d1c80ab7538e4157112e  cytoscape-dagre-2.5.0/cytoscape-dagre.js
08fd7545d13d2c7fb65ab691530a802dafefd638596501854f267d0fb13c39e7  three-0.170.0/three.module.min.js
```

Aktualizace: stáhnout novou verzi (`npm pack <balíček>@<verze>`), nahradit soubory v adresáři s novou verzí v názvu, upravit tuto tabulku a odkazy v `templates/topology.php` (three.js v `public/assets/js/topology3d.js`).
