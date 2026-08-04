=== Quiz Atelier ===
Contributors: effiek
Tags: quiz, poll, personality test, organizations, analytics, embed
Requires at least: 6.2
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later

Multi-tenant Quiz Studio για Organizations, Creator Admins και Quiz Creators.

== Description ==

Το Quiz Atelier χρησιμοποιεί custom database tables και ξεχωριστό full-screen Studio αντί για metaboxes.

Η Organizations Edition προσθέτει:

* Organizations/Workspaces με domains, θέσεις, όρια Creator Admins, ημερομηνία λήξης, feature flags και branding.
* Private, Organization και Universal ορατότητα quiz.
* Creator, Creator Admin, Universal Manager και WordPress Administrator permissions.
* Front-end Creator Dashboard, team management και secure invitations.
* Editorial workflow, review comments, version history και activity log.
* Quiz templates, βιβλιοθήκη ερωτήσεων, κατηγορίες και full analytics.
* Embed Center, whitelist domains, iframe/JavaScript/shortcode/Drupal codes και preview.
* Dark Atelier UI, προσωπικό style ανά account, custom scrollbar και accessibility refinements.

Author: Έφη Κακούνη — effiek.gr

== Shortcodes ==

* `[wp_quiz_studio id="25"]` — ένα δημόσιο quiz.
* `[wp_quiz_studio_directory]` — κατάλογος quiz.
* `[wp_quiz_studio_builder]` — προστατευμένο front-end Creator Studio.
* `[wp_quiz_studio_portal]` — alias του front-end Creator Studio.

== Upgrade Notice ==

Η 0.9.5 διατηρεί τα υπάρχοντα quiz και memberships. Προσθέτει Workspace controls, domain-based embed whitelist και νέο theme-native interface. Πάρτε backup βάσης πριν από την αναβάθμιση.

== Changelog ==

= 0.9.5 =
* Νέο ενιαίο interface που ακολουθεί τα design tokens του Quiz Atelier theme 3.7.0.
* Πραγματικό Light/Dark mode ανά χρήστη, αποθηκευμένο στις προσωπικές προτιμήσεις.
* Νέα καρτέλα «Το Workspace μου» για Creator Admin και WordPress Administrator.
* Νέα καρτέλα «Χρήστες & Workspaces», ορατή μόνο σε WordPress Administrators.
* Μεταφορά χρηστών μεταξύ Workspaces, με προαιρετική μεταφορά των quiz τους.
* Ο Creator Admin δεν μπορεί να αλλάξει, να αναστείλει ή να αφαιρέσει WordPress Administrator.
* Η embed whitelist κληρονομείται από τα εγκεκριμένα domains του Workspace.
* Suspended ή expired Workspace δεν μπορεί να εξυπηρετεί embeds.
* Ανασχεδιασμένα navigation, cards, tables, forms, modals, builder και responsive layouts.

= 0.9.1 =
* Πλήρης builder, playable preview, public player, validation και server-side scoring για 12 τύπους ερωτήσεων.
* Υποστήριξη για Μία επιλογή, Πολλαπλές επιλογές, Σωστό/Λάθος, Επιλογή εικόνας, Poll, Ανοιχτό κείμενο, Numeric, Slider, Rating, Ordering, Ranking και Matching.
* Exact ή partial scoring για πολλαπλές απαντήσεις, ordering/ranking και matching.
* Drag & drop και βελάκια προσβασιμότητας για ordering/ranking.
* Ρυθμίσεις πεζών/κεφαλαίων, τόνων και σημείων στίξης για ανοιχτό κείμενο.
* Αποτροπή διπλών επιλογών στο matching και stars/numbers εμφάνιση στο rating.
* Διορθώθηκε η αλλαγή τύπου χωρίς submit, απώλεια δεδομένων ή μεταφορά στην κορυφή.
* Προστέθηκε theme bridge που κληρονομεί με ασφάλεια το ενεργό Quiz Atelier theme, χωρίς global CSS παρεμβάσεις.
* Διατηρείται η διόρθωση του fatal error στην επαναποστολή πρόσκλησης της 0.8.7.

= 0.8.1 =
* Organizations, domains, seats, Creator Admin limits, expiry and feature flags.
* Private / Organization / Universal quiz visibility.
* Creator and Creator Admin dashboards, invitations and membership management.
* Approval workflow, comments, notifications and activity log.
* Organization and Universal templates.
* Tenant-safe analytics, categories, question library and REST access.
* White-label organization branding and embed domain controls.
* Enhanced Embed Center and browser Print/PDF analytics.
* Full-width Studio, fixed light/dark preset contrast and custom scrollbar.
* White Edit button text and icon/tooltip for saving questions to the library.

== Changelog ==

= 0.9.9 =
* Builder accordion: ανοίγει μία ερώτηση κάθε φορά για καθαρότερο editing.
* Drag-and-drop αναδιάταξη ερωτήσεων και απαντήσεων.
* Inline validation με σύνδεση απευθείας στο προβληματικό πεδίο.
* Sticky action bar με Έλεγχο, Δοκιμή, Αποθήκευση και Δημοσίευση.
* Βελτιωμένο workflow stepper και έλεγχος πριν από την υποβολή.
* Keyboard shortcuts για αποθήκευση, preview και νέα ερώτηση.

= 0.9.8 =
* Creator Admin seat and lifecycle controls are hidden and API-protected.
* Workspace whitelist now uses CSP frame-ancestors for reliable iframe permissions.
* Consolidated Library, Templates, Analytics and Workspace spacing and layouts.

= 1.0.0 =
* Added System Status and safe repair tools.
* Added conflict-safe editing and offline recovery.
* Replaced repeated 2-second autosave with debounced autosave.
* Refreshed production assets and fixed stale stylesheet references.
