Redundancy  1.9.x unstable branch
=================================
> Redundancy is an lightweigth cloud computing systems. The program is very lightweight so you can run it on microservers, like the Raspberry Pi without having too much load.
Redundancy does not require full server access, it can be installed on every server running a webserver using php. Redundancy uses an very easy way of configuring throught a central configuration file.
Redundancy does not have an "bling bling" configuration wizard at the moment. The stable branch will probably get an installer and an installation wizard, too. The biggest difference between Redundancy and other programs for this purpose is that you can have a very lightweight user
expierience. There are no unnecessary features you probably never use. Redundancy concentrates on the core of the task to create an easy cloud, for example to use at home.

Requirements
============

- PHP 5
- PHP GD modules
- PHP zip modules
- MySQL(i) or equivalent
- Client: JQuery Support (if enabled, but iit's recommended to enable JavaScript in your browser!)

License and components
======================

- Redundancy is licensed under the terms and conditions of the GNU GPL v3.
- Redundancy uses JQuery, it can be found on https://jquery.org/.
- Redundancy uses parts of Faenza, it can be found on http://gnome-look.org/content/show.php?content=128143
- Redundancy uses Twitter Bootstrap lizenced under the terms and conditions of Apache License 2.0
- Redundancy uses jqPlot (http://www.jqplot.com/), licenced under the terms and conditions of  MIT and GPL version 2
- Redundancy uses webfont Elusive (http://shoestrap.org/downloads/elusive-icons-webfont/), licenced under the terms and conditions of the SIL Open Font License (OFL)
- Redundancy uses a pattern from http://subtlepatterns.com/

Note/ Disclaimer
================

Redundancy's default branch is unstable. At the moment there is no stable version available. The program runs on every configured server, but there is
the possibility to loose data because of hidden bugs is very high. Please feel free to inform me about these issues over the github issue tracker. Thanks :).

Since 1.9.7 it is possible to create snapshots. It will be recommended (if the feature is out of beta state) to run these snapshots via cronjobs or tasks cyclic to avoid data loss.