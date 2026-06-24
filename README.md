# LightEvents for WordPress

Plugin WordPress pour intégrer LightEvents comme Eventbrite, mais adapté Mobile Money, WhatsApp, LinkedIn, CRM et PME Afrique/diaspora.

## Documentation

Voir la documentation fonctionnelle centrale dans le backend : `lightEventsBA/docs/LIGHTEVENTS_GUIDE_FR.md`.

## Installation

1. Copier le dossier `lightEventsWP` dans `wp-content/plugins/lightevents`.
2. Activer **LightEvents for WordPress**.
3. Aller dans **Réglages → LightEvents**.
4. Configurer l'URL API, par exemple `https://api.votre-domaine.com/api`.

## Shortcodes

```txt
[lightevents_events]
[lightevents_events view="calendar" country="Côte d'Ivoire" category="business"]
[lightevents_events view="list" organizer="MWEMBA"]
[lightevents_event id="123"]
[lightevents_checkout event="123"]
```

## Blocs Gutenberg

- LightEvents - Liste d'événements
- LightEvents - Détail événement

## Ce que fait la v0.1

- Lecture automatique des événements depuis l'API LightEvents.
- Vues grille, liste/agenda, calendrier simple et carte préparée.
- Filtres pays, ville, catégorie, organisateur.
- Détail événement dans WordPress ou redirection vers LightEvents.
- Formulaire de réservation.
- Envoi des tickets QR par email via le backend LightEvents après réservation/paiement.
- Paiement depuis WordPress via GetMiPay pour Orange Money, MTN MoMo, Wave, Airtel Money, Moov Money, plus carte/Stripe et PayPal.
- Champ OTP pour les services Orange Money qui l’exigent.

## Roadmap proche

- Vrai widget Elementor.
- Synchronisation cache WordPress + webhooks.
- Création d'événements depuis WordPress.
- Invitations WhatsApp natives.
- Networking LinkedIn et CRM leads/sponsors.
- Dashboard organisateur embarqué.
