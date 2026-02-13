=== AI Auto Blog ===
Contributors: Hateem
Tags: ai, content generation, autoblog, seo, openai, automation
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Automatically generate SEO-optimized blog posts using AI with full campaign control, scheduling, keyword management, and image generation.

== Description ==

AI Auto Blog is an advanced campaign-based content generation plugin for WordPress.

It allows you to:
- Create AI-powered content campaigns
- Control keyword rotation and limits
- Schedule automatic post generation
- Generate featured and inline images
- Apply SEO rules (links, alt text, titles)
- Run campaigns manually or via cron
- Track campaign status (Running, Paused, Completed)  

== Features ==

**Campaign System**
- Enable / Disable campaigns
- Pause autorun without disabling manual runs
- Campaign completion detection
- External cron trigger support
- One campaign = multiple AI posts

**Keyword Control**
- One post per keyword
- Keyword rotation (randomized order)
- Stop automatically when keywords are exhausted
- Resume logic handled safely
- Optional keyword-as-title

**AI Content Generation**
- Supports OpenAI and OpenRouter
- Custom title prompt
- Custom content prompt
- Min / Max word limits
- Token and temperature overrides
- Safe continuation handling for long articles

**Scheduling**
- WP-Cron single-event scheduling
- Custom interval (minutes / hours / days)
- Optional fixed daily run time
- External secure cron URL support

**Post Settings**
- Custom post type
- Custom post status
- Author selection
- Category assignment
- Draft or publish modes

**Image Integration**
- Featured image generation
- Inline content images
- DALL·E image support
- Custom image prompts
- Image placement control
- Alt text rules:
  - Use title for all images
  - Use title only when alt is empty

**SEO Rules**
- Remove all links
- Force links to open in new tab
- Apply nofollow to links
- Clean HTML output
- No AI disclosure text

**Admin UI**
- Custom campaign list
- Clear status indicators:
  - Running
  - Paused
  - Completed
  - Disabled
- Run Now button
- Safe edit locking when enabled

== Configuration ==

**Required**
- OpenAI API key OR OpenRouter API key

**Optional**
- Model selection
- Token limits
- Temperature
- Image generation settings
- External cron usage