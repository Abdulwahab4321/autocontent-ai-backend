# WordPress plugin (AutoContent AI / ai-auto-blog)

This folder is the **source** of the plugin. The plugin is stored in **MongoDB (GridFS)** and downloaded from there.

- **Upload to DB:** From `backend/` run: `npm run plugin:upload`  
  This zips `ai-auto-blog/` and stores it in GridFS. Run once after clone, and whenever you replace the plugin files.
- **Download:** Users get the plugin from the API (dashboard “Download plugin”); the backend streams the zip from GridFS.

To update the plugin: replace the contents of `ai-auto-blog/`, then run `npm run plugin:upload` again.
