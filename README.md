# LightEvents for WordPress

Plugin WordPress officiel pour intégrer LightEvents à un site existant avec une expérience proche d'Eventbrite, mais optimisée pour le modèle LightEvents : Mobile Money africain, carte/PayPal, QR tickets, check-in mobile, promo codes, réservations temporaires, SEO WordPress et synchronisation catalogue.

## Ce que le plugin apporte

- Menu WordPress dédié **LightEvents** avec Dashboard, Events, Import / Sync, Settings, Shortcodes, Wizard et Support.
- Onboarding après activation, comme les plugins événementiels premium.
- Custom Post Type `lightevents_event` avec catégories/tags WordPress pour SEO local.
- Import d'un événement par ID ou URL LightEvents.
- Synchronisation catalogue des événements publiés LightEvents vers WordPress.
- Shortcodes front : grille, agenda, calendrier, détail événement et checkout.
- Billetterie avec réservation ou paiement direct : Orange Money, MTN MoMo, Wave, Airtel Money, Moov Money, Stripe et PayPal.
- Support promo codes, QR tickets par email, disponibilité des places, frais plateforme LightEvents transparents.

Voir la documentation fonctionnelle centrale dans le backend : `lightEventsBA/docs/LIGHTEVENTS_GUIDE_FR.md`.

## Installation

1. Copier le dossier `lightEventsWP` dans `wp-content/plugins/lightevents`.
2. Activer **LightEvents for WordPress** depuis WordPress.
3. Suivre le Wizard d'accueil.
4. Configurer **LightEvents → Settings** :
   - API LightEvents : `https://lighteventstest.onrender.com/api` ou votre API production.
   - URL plateforme : `https://valere-merval.github.io/lightEventsFE` ou votre frontend production.
   - Token API organisateur LightEvents si nécessaire.
5. Importer un événement ou synchroniser le catalogue dans **LightEvents → Import / Sync**.

## Shortcodes

```text
[lightevents_events]
[lightevents_events view="agenda" country="Côte d'Ivoire" category="business"]
[lightevents_events view="calendar" limit="30"]
[lightevents_events source="wordpress"]
[lightevents_event id="123"]
[lightevents_checkout event="123"]
[lightevents_event_from_query]
```

## Checklist production

- Utiliser uniquement des URLs HTTPS stables, pas des tunnels temporaires.
- Configurer les emails transactionnels côté backend pour l'envoi des QR tickets.
- Vérifier que les moyens de paiement GetMiPay/Stripe/PayPal sont actifs.
- Autoriser le domaine WordPress dans la configuration CORS backend si nécessaire.
- Garder le token organisateur LightEvents privé et distinct des tokens GitHub.

## Notes développeur

Le plugin ne stocke pas de token GitHub. Les tokens transmis pour pousser le code doivent rester transitoires et être révoqués/rotés après usage.
