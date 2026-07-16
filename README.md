# BA AVIF Converter — AVIF + WebP pour WordPress, 100 % local et gratuit

🇬🇧 **[Read this in English →](./README.en.md)**

**Plugin WordPress qui convertit vos images JPEG/PNG/GIF en AVIF et WebP, encodées localement (Imagick ou GD), et les sert en cascade AVIF → WebP → original via .htaccess — sans jamais toucher à vos fichiers d'origine.** Développé par [Bernard David Corroy](https://www.david-corroy.com/) pour [BuzzArena](https://www.buzzarena.com/), où il tourne en production sur des dizaines de milliers d'images.

Tout ce que les convertisseurs du marché font payer est ici gratuit : la sortie AVIF, le mode AVIF + WebP, et l'encodage local (aucune image envoyée à un serveur tiers).

## Comment ça marche

Le plugin ne modifie **jamais** vos images. Il crée des copies dans un miroir `wp-content/uploads-avifc/` qui reproduit l'arborescence d'uploads, puis des règles `.htaccess` servent la meilleure version que le navigateur accepte :

```
Navigateur accepte l'AVIF ?  → uploads-avifc/2026/07/photo.jpg.avif
Sinon, accepte le WebP ?     → uploads-avifc/2026/07/photo.jpg.webp
Sinon                        → uploads/2026/07/photo.jpg (l'original)
```

Désactivez le plugin : les règles sont retirées, le site sert à nouveau les originaux, rien n'est perdu.

## Fonctionnalités

- **Nouveaux uploads convertis dans la seconde** — auto-appel non bloquant en fin d'upload, avec double filet (WP-Cron + priorité « uploads récents » du prochain lot)
- **Optimisation en masse** du stock existant : par lots, avec pause / reprise / reconversion forcée, et anneaux de progression en direct
- **Ordre « récentes d'abord »** : vos articles chauds (accueil, Discover) profitent de l'AVIF dès les premières heures, les archives suivent
- **Scan disque complet** : ramasse aussi les images présentes sur le disque mais absentes de la Médiathèque (FTP, plugins qui écrivent en direct)
- **Thèmes et plugins** convertis en option (logo, sprites…)
- **Imagick ou GD** avec repli automatique si la méthode choisie ne sait pas produire un format
- **Garde-fous** : copie plus lourde que l'original supprimée (marqueur `.skip`), écriture atomique (`.tmp` puis `rename` — jamais de fichier tronqué servi), verrou fichier anti-chevauchement
- **Pensé pour l'hébergement mutualisé** : lots courts, 3 s de pause entre les lots, lecture d'options directement en base (contourne les caches objet APCu non partagés web/CLI), et un **déclencheur cron serveur direct** (`admin-post.php?action=ba_avif_tick&key=…`) qui contourne WP-Cron quand il est capricieux
- **Colonne Médiathèque** : réduction moyenne par image, fichiers convertis, bouton « Convertir maintenant »
- **Réglages complets** : qualité AVIF et WebP séparées, extensions sources (.png/.gif/.webp), répertoires exclus, métadonnées EXIF, journalisation

## Installation

1. Téléchargez le dossier `ba-avif/` et déposez-le dans `wp-content/plugins/`
2. Activez **BA AVIF Converter** (le plugin refuse de s'activer si le serveur ne sait encoder aucun des formats choisis — au moins Imagick avec AVIF, ou GD sous PHP 8.1+)
3. **Réglages → BA AVIF** : choisissez le format de sortie (AVIF + WebP recommandé), puis « Démarrer l'optimisation en masse »
4. Sur mutualisé, collez le déclencheur cron affiché dans « Réglages avancés » dans une tâche cron cPanel (toutes les 5 minutes) — recommandé
5. Purgez votre cache et vérifiez : clic droit sur une image du site → Inspecter → onglet Réseau → la colonne Type doit afficher `avif`

## Prérequis et limites (honnêtes)

- **Apache ou LiteSpeed** (règles `.htaccess`) — testé en production sur o2switch. Pas de support Nginx à ce jour.
- AVIF : Imagick compilé avec libheif, **ou** GD sous PHP 8.1+ avec libavif. La page de réglages affiche l'état exact de votre serveur.
- GIF animés : seule la première image est conservée — laissez `.gif` décoché si vous en utilisez.
- L'en-tête `Vary: Accept` est envoyé pour les caches/CDN ; purgez le cache après activation.

## Licence

GPL-2.0-or-later — comme WordPress. Utilisez, modifiez, redistribuez librement.

## Aller plus loin

- 🔬 [Audit express SEO & GEO](https://www.david-corroy.com/audit-express/) — testez la vitesse et la visibilité IA de votre site, gratuit, 60 secondes
- 📚 [Prompts GEO](https://github.com/david-corroy/prompts-geo) — être cité par ChatGPT, Perplexity et Gemini
- 📊 [Grille de suivi de visibilité IA](https://github.com/david-corroy/grille-suivi-visibilite-ia)

**L'auteur** : Bernard David Corroy, consultant SEO & GEO indépendant, fondateur de Phonandroid et BuzzArena — [Site](https://www.david-corroy.com/) · [LinkedIn](https://www.linkedin.com/in/bernard-david-corroy/) · [Wikidata](https://www.wikidata.org/entity/Q140472682) · [Malt](https://www.malt.fr/profile/davidcorroy) · [Crunchbase](https://www.crunchbase.com/person/bernard-david-corroy)
