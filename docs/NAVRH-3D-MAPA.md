# Proposal: layered 3D infrastructure map

Status: the owner agreed with the recommendations on 2. 11. 2026. Step A (floors in 2D) is done in phase F8, step B (3D prototype) in phase F9, step C (search, problem mode, toggling floors) in phase F10 and step D (container and cluster types, metrics in 3D) in phase F11. The decisions are in `docs/ROZHODNUTI.md` (F8 to F11). Date: 2026-11-02.

## 1. The idea

The owner wants to show the links between objects in 3D space. The map object would be multi-layered, for example a sphere or a cylinder with several spheres or cylinders inside it. Each layer would hold one kind of object: hardware (disks, CPU, RAM), VPS, Docker, Kubernetes, internet, domains, projects, documentation, logs. The model could be rotated toward the place where a problem is being solved, zoomed in and searched. The inspiration is a 2D cluster map and the controls from the film Minority Report.

Motivation: as objects are added, the 2D map turns into a tangle of lines.

## 2. What is good about the idea

- **Layers match how people think about infrastructure:** hardware -> virtual machines -> containers -> services -> websites -> domains -> internet. Anyone looking for the cause of a website outage goes down exactly this path.
- **Two kinds of links get separated.** The "contains" link mostly runs between adjacent layers (a server contains a VM), "depends on" often across them (a website depends on a database). When the height is given by the layer, the kind of link is visible from the slope of the line.
- **Rotating toward the problem** is a good way to focus attention without losing the context of the surroundings.
- **It is technically feasible.** Every current browser supports WebGL, and hundreds of nodes with labels are a small load for it.

## 3. What to watch out for

- **Occlusion.** With spheres or cylinders nested inside each other, the inner layers are hidden behind the outer ones and half of each shell faces away. At any moment a large part of the objects is at the back or behind something. Transparency softens this, but intersecting lines from several shells are harder to read than in 2D.
- **The tangle of lines does not disappear by itself.** 3D spreads it out in space, but when rotating, the lines cross again. The main remedy is to show less: the neighborhood of the selected object, the path to the cause, a filter. Nordvarr can already do this in 2D (F4) and it would be needed in 3D as well.
- **Perspective distorts.** Distant objects are smaller and labels overlap. Text in 3D is harder to read than in a plane.
- **Controls.** Rotating with the mouse is slower for a quick check than 2D panning and zooming, and even more so on a phone. 3D should therefore be a second view next to 2D, and 2D should stay the default.
- **Tests.** WebGL in the test browser runs in software and slowly. It is possible to verify that the scene renders, that there are no errors in the console and that clicking and search work. Comparing screenshots would be flaky.
- **Size.** The three.js library is several hundred kB. It would have to be included in `assets/vendor` with a pinned version (project rules, no CDN).

Summary: 3D pays off as a view of the whole and as a path to a problem. For routine work with dozens of objects, 2D with a filter is enough.

## 4. Shape variants

| Variant | What it looks like | Plus | Minus |
|---|---|---|---|
| A. Nested spheres | each layer is a spherical shell | impressive | occlusion, half always at the back, hard to search in |
| B. Nested cylinders | each layer is a cylinder surface | the height of the surface can carry another value | the inner surfaces are hidden just like with spheres |
| C. Stacked floors (recommended) | a cylinder cut into horizontal floors, each floor is one layer | seen obliquely from above everything is visible, the top view gives a 2D map, floors can be spread apart | less spectacular than shells |
| D. 2D rings | layers as concentric circles in a plane | no 3D, works everywhere | as objects are added, the outer rings get overcrowded |

**Recommendation: variant C.** It keeps your cylinder, only the layers are not nested but stacked on top of each other. This gives a natural arrangement:

- **height = layer** (hardware at the bottom, internet at the top),
- **angle = "column"**: everything that runs on one physical machine or at one provider lies above each other in the same direction. A physical server with VMs, containers and websites above it thus forms a vertical column, and a problem on the hardware is visible as a problem of the whole column,
- **the "contains" link** is then an almost vertical short line, **"depends on"** an oblique line between columns, so the two kinds of links differ at first glance.

The layout is deterministic (from the data, not from a random simulation). The map therefore looks the same every time, even though SPEC does not include storing manual positions in the MVP.

## 5. Layers in Nordvarr data

Current object types: `server`, `vm`, `service`, `website`, `database`, `domain`, `network_device`, `other`.

Proposed floors (bottom to top):

| Floor | Content | Types today | To add |
|---|---|---|---|
| 1 Hardware and network | physical servers, network devices | `server`, `network_device` | |
| 2 Virtualization | VMs and VPS | `vm` | |
| 3 Containers | Docker, Kubernetes (cluster, node, pod) | none | type `container`, possibly `cluster` |
| 4 Services and databases | applications, queues, DBs | `service`, `database` | |
| 5 Websites and APIs | websites, public APIs | `website` | |
| 6 Domains | domains and DNS | `domain` | |
| 7 Internet and external services | CDNs, tunnels, third-party APIs | `other` | possibly type `external` |

A VPS at a provider whose hardware we do not know lies on floor 2 in the "provider" column.

**I do not recommend projects, documentation and logs as floors.** They are not infrastructure layers; they concern objects across floors:

- **a project** is a group of objects. It works better as a filter or a color (it highlights its columns and dims the others). Today it can be expressed with a tag,
- **documentation and incident history** belong to an object. They are shown in the side panel of the selected object (the object card from F4 already has the data),
- **logs** are not collected by Nordvarr. Log collection would be a big new area, and full-text search over history is outside the MVP in SPEC.

## 6. Controls (a realistic Minority Report)

- rotating by dragging, zooming with the wheel or fingers, double click = the camera flies to the object,
- **search**: you type a name, the camera flies to the object and highlights it,
- **problem mode**: with an open alert, everything outside the path "object -> probable cause" is dimmed and the camera turns toward it. The cause data already exist from F4,
- turning individual floors off and on, spreading the floors apart,
- **top view** with one button (smooth transition to 2D),
- **side panel** as on the reference map: status, agent metrics (CPU, RAM, disks, temperatures), alerts, documentation, recent events.

I do not propose control by hand gestures in space (camera, sensors).

## 7. What to take from the reference 2D map even without 3D

- metrics directly in the node (CPU, RAM, disk bars): Nordvarr has them from the agent (F6),
- a summary panel on the right and a panel of external services,
- latency on edges: Nordvarr measures the latency of checks from its own server, not between nodes. Edges could only show the status and latency of the target object's check.

The reference map is laid out by hand for one cluster with seven nodes. Nordvarr must be able to lay out arbitrary data, so it needs rules (floors and columns) instead of manual placement.

## 8. Technology

- **three.js** (MIT license) with a pinned version in `public/assets/vendor`, with an entry in `VERSIONS.md`. For a fixed layout the library itself and camera controls are enough. Libraries with force simulation (e.g. 3d-force-graph) are not needed.
- **Labels** as an HTML layer above the scene (CSS2DRenderer): the text is sharp and readable. Styles are set by the script via CSSOM, which CSP without inline styles allows. This still has to be verified, just as it was handled for Cytoscape (ROZHODNUTI F4 item 7).
- **Data** from the existing topology JSON (with ETag) extended with floor and column. The layout is computed by the server in PHP, so that it can be covered by unit tests.
- **Performance**: hundreds of nodes without problems. With more than a thousand nodes instancing would be needed, but the target size of Nordvarr (low hundreds) does not need it.
- **Fallback**: without WebGL the 2D map remains.

## 9. Step-by-step plan

1. **Step A, floors in 2D (small, for verification).** The current 2D map gets a "layers" mode: horizontal bands by floor, objects in columns. It shows whether the floor model fits real data, and none of it is thrown away.
2. **Step B, read-only 3D prototype.** Floors, columns, rotation, zoom, click -> side panel. Try it first on demo data, then on yours.
3. **Step C, working with problems.** Search, problem mode, toggling floors, transition to the top view.
4. **Step D (optional).** The types `container` and `cluster`, agent metrics directly on nodes.

Between steps A and B it is possible to decide, based on experience, whether 3D really adds more than 2D with floors.

## 10. To decide

1. Stacked floors (recommended), or nested shells?
2. The list and order of floors, add the types `container` and `cluster`?
3. Projects as tags (recommended), or a new object type?
4. Logs out of scope (recommended), or a future separate area?
5. 3D as a second view next to 2D (recommended)?
6. Start with step A?

The 3D map is not in SPEC. It would be another phase beyond the scope of SPEC, like F6 and F7, with a record in `docs/ROZHODNUTI.md`.
