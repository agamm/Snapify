# Snapify

![Snapify Header](https://raw.githubusercontent.com/funerr/Snapify/master/readme-images/header.jpg)

**Created By [@amitgafny660](https://github.com/amitgafny6600) & [@funerr](https://github.com/funerr)**

Snapify lets you finally move your WordPress website easily.

### Why?
We felt how bad it was to move WordPress sites, configuring unnecessary extract options, unpacking, replacing URLs and tweaking files – We didn’t find any truly simple and automatic solution out there, so we decided to create this easy to use product. We hope you will find it useful like we did. For more information you can visit us at: https://snapifybackup.com

1) On your old-host.com: Create a backup file. 
2) On your new-host.com: Upload the backup file and execute Snapify install script.
3) Then the website is identical to your old-host.com works under new-host.com!

### Usage:
1) Install the Snapify plugin on your WordPress Site 

    \->    Zip the plugin folder and upload it as a regular WordPress plugin.
2) Export! 
3) Upload Exported files to new server and click install! 
4) Done! 

### Features:
- Backup every aspect of your WordPress site (database, plugins, content, themes and settings) 
- Does not require WordPress to be installed first – uses Snapify install script. 
- 1 Click export no FTP required
- Download backup as a zip file.
- Full Documentation & Video Demonstration included.
- Auto detect new server settings upon install
- No cloud required
- Compatible with old PHP & MySQL versions
- Optimized for low end machines
- Restore site in one click
- Handles .htaccess correctly
- Handles Unicode
- Stream Download – Backup’s on the fly without using space
- Windows OS support

### Testimonials
![Testimonials](https://raw.githubusercontent.com/funerr/Snapify/master/readme-images/testimonials.png)

_These where taken from the previous codecanyon page._

### Known Issues

If you have a large website Snapify may take a while to process, please be patient (10,20,30 minutes +).
Does not handle multi-site as one website yet.
Does not work with cloudflare at the moment. 
Does not work with hostings that have a low php execution time that can’t be changed or can’t use/install the php zip extension 
Please contact us before buying if you think you have one of these problems

_If you can solve one of these problems, please send us a PR, it will be awesome!_

### Updates

1.3.3 – (8/8/17)
- Compress Performance Improvement
- Advanced Options Toggle UI
- Add InnoDB option in Installer when “row too big”.
- Added smart automatic backup method (when advanced toggle is off)

1.3.2 – (8/3/2017)
- Support complex table manipulation
- Fixed Minor Bugs

1.3.1 – (6/27/2017)
- Windows OS support
- Performance improvements
- Bug fixes
 
1.3.0 – (6/8/2017)
- Stream Download – doesn’t require space on the server and shows progress
- Major performance improvements
- Bug fixes

1.0.2 – (3/30/2017)
- Added Force UTF8 option when backupping (fixes several encoding errors)
- Added debug-log
- Created more verbose error messages
- Fixed UI elements in the installer
- Bug Fixes & Performance improvements to the system
- Enhance the documentation and add solutions for common problems.

1.0.1 – (2/13/2017)
- Add support to MySQLi in addition to MySQL modules
- Added a Video Tutorial
- Bug fixes
- Performance improvements to the system

### What does Snapify do technically?
Snapify basically takes all of your website’s files and stores them in a zip file with an export of your database in an SQL format. 
In addition to that snapify adds some `metadata` like your old path (so we could replace it later on) and encoding your database used.
When you want to restore all that, snapify does it automatically.

### Youtube tutorials:
[![Introduction](http://img.youtube.com/vi/T2VS4nM-jho/0.jpg)](http://www.youtube.com/watch?v=T2VS4nM-jho)
[![Backup Phase](http://img.youtube.com/vi/HPmE0CEVC60/0.jpg)](http://www.youtube.com/watch?v=HPmE0CEVC60)
[![Installer Restore Phase](http://img.youtube.com/vi/KudGJ7qhaRs/0.jpg)](http://www.youtube.com/watch?v=KudGJ7qhaRs)
