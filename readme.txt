=== WP Nano Banana ===
Contributors: pbalazs
Tags: ai, images, openai, replicate, google
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free WordPress plugin for generating and editing images with AI directly inside your WordPress dashboard. Works with OpenAI, Google Gemini (Nano Banana), Replicate API, Seedream 4.0, and more.

== Description ==

WP Nano Banana is a clean, free, and open-source WordPress plugin that brings AI image generation and editing directly into your WordPress admin area.  

**Key capabilities**:
- Generate images from text prompts
- Edit and enhance existing images
- Combine multiple images from your Media Library or uploads
- Seamlessly integrates with Gutenberg, Classic Editor, and most page builders

Unlike many AI plugins, WP Nano Banana has:
- **No ads, no locked features, no upsells**
- **Native-like UI** that blends into WordPress core
- **Secure, performant code** built for speed and reliability
- **BYOK (Bring Your Own Key)** support for OpenAI, Google, Replicate, and others

### Features

- **AI Image Generation**
  - Generate new images from simple text prompts
  - Add reference images (from your computer or Media Library) to guide output
  - Works for featured images, blog graphics, product photos, and more

- **AI Image Editing**
  - Select images from your Media Library to edit
  - Combine images into composites
  - Apply targeted edits like background replacement, style adjustments, or object regeneration

- **Media Library Integration**
  - Generate and edit directly from the Media Library
  - All results are saved automatically to your Media Library
  - Compatible with image fields across WordPress, themes, and page builders

- **Developer-Friendly**
  - Clean, extensible codebase
  - Hooks and filters for customization
  - Easy to audit and extend

### Supported AI Models

WP Nano Banana supports a wide range of AI image models:

- **Google Gemini 2.5 Flash Image**, a.k.a. *Google Nano Banana* (`gemini-2.5-flash-image`)
- **OpenAI GPT-Image-1** (`gpt-image-1`) — same model ChatGPT uses for image generation
- **Google Imagen 4** (`google/imagen-4`, `google/imagen-4-ultra`, `google/imagen-4-fast`)
- **FLUX Models** (`flux-kontext-max`, `flux-1.1-pro`, `flux-schnell`, `flux-dev`)
- **Recraft v3**, **Reve Create**, **Ideogram v3**, **Seedream 4.0**, **Qwen Image** and more

### External Services

The plugin depends on third-party APIs for AI image generation and editing.  
No data is transmitted until you configure your API credentials in settings.

**OpenAI**
- Used for AI image generation/editing via `gpt-image-1`
- Data sent: prompts, optional reference images
- [Terms of Use](https://openai.com/policies/terms-of-use)  
- [Privacy Policy](https://openai.com/policies/privacy-policy)  

**Google Generative AI**
- Used for image generation/editing (Gemini Nano Banana, Imagen models)
- Data sent: prompts, optional reference images
- [Terms of Service](https://policies.google.com/terms)  
- [Privacy Policy](https://policies.google.com/privacy)  

**Replicate API**
- Provides access to third-party AI image models (Seedream, FLUX, Recraft, Ideogram, etc.)
- Data sent: prompts, optional reference images
- [Terms of Service](https://replicate.com/terms)  
- [Privacy Policy](https://replicate.com/privacy)  

---

== Installation ==

1. Upload the `wp-banana` folder to your `/wp-content/plugins/` directory, or install it via the Plugins screen in WordPress.
2. Activate **WP Nano Banana** from the Plugins menu.
3. Go to **Settings → WP Nano Banana** and enter your API key(s).
4. Start generating and editing images directly inside WordPress.

---

== Frequently Asked Questions ==

= Does WP Nano Banana require an API key? =
Yes. You will need your own API key from OpenAI, Google AI Studio, Replicate, or another supported provider.

= Are there any restrictions or locked features? =
No. WP Nano Banana is 100% free and open source, with no ads or premium upsells.

= Does it work with my page builder? =
Yes. WP Nano Banana works with Gutenberg, Classic Editor, and all major page builders since it integrates directly with the Media Library.

= Where are my generated images stored? =
All images are saved directly into your WordPress Media Library. You can use them like any other image in WordPress or download them.

---

== Screenshots ==

1. Generate AI images from prompts  
2. Edit images inside the Media Library  
3. Combine multiple images into a new AI composite  
4. Settings page for configuring API credentials  

---

== Requirements ==

- WordPress 6.0 or higher  
- PHP 7.4 or higher  
- API key from a supported provider  

---

== Changelog ==

= 0.2.0 =
* Improved UI for image editing
* Added more AI models via Replicate API integration
* Added option to set API keys via constants
* Added various filter and action hooks for developers
* Bug fixes and performance improvements

= 0.1.0 =
* Initial release with AI image generation, editing, and Media Library integration
* Support for OpenAI gpt-image-1, Google Gemini Nano Banana, and Replicate API models

---

== License ==

This plugin is licensed under the GPLv2 or later.  
https://www.gnu.org/licenses/gpl-2.0.html
