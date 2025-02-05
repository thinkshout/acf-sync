# Advanced Custom Fields Syncronizer

A Wordpress plugin that helps import and export ACF configurations to JSON for version management integration.

This plugin is intended for use with ThinkShout's [Base Assets](https://github.com/thinkshout/base-assets), [Wordpress Starter](https://github.com/thinkshout/think-wp-web-root-starter), and [Wordpress Starter Theme](https://github.com/thinkshout/thinkwp-starter-theme).

In particular, the paths used for finding and saving json files based on an individual ACF configuration are specific to the patterns established in the Base Assets and WP Starter Theme above.

## Usage

Add to your composer.json file's "repositories" section:

    {
      "type": "vcs",
      "url": "https://github.com/thinkshout/acf-sync"
    },

1. Install this plugin using composer `composer require thinkshout/acf-sync`
2. Enable the plugin in the WP UI
3. Create or edit an ACF configuration. It should save automatically to the appropriate location in the theme.