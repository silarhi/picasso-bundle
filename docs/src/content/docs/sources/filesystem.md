---
title: 'Filesystem'
description: 'Serve images from local directories.'
sidebar:
    order: 1
---

Reads images from a local directory. Each filesystem loader reads from exactly one `path`; declare one loader per directory:

```yaml
picasso:
    default_loader: uploads
    loaders:
        uploads:
            type: filesystem
            path: '%kernel.project_dir%/public/uploads'
        assets:
            type: filesystem
            path: '%kernel.project_dir%/assets/images'
```

```twig
<twig:Picasso:Image src="photos/landscape.jpg" width="800" height="600" alt="Landscape" />
<twig:Picasso:Image src="logo.png" loader="assets" width="200" height="80" alt="Logo" />
```

Paths escaping the directory (`../`) are treated as missing images.
