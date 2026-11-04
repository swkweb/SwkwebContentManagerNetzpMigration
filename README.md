# Content Manager | Migration from NetzpBlog6

Moves the NetzpBlog6 blog into the Content Manager and reports everything that cannot be moved or behaves differently afterwards.

## Requirements

- Shopware 6.7.5 or newer
- Content Manager 4.4.0 or newer, installed and active
- The NetzpBlog6 tables; NetzpBlog6 itself may already be deactivated

## Usage

Create a database backup first: the migration changes CMS layouts and SEO URLs of the shop.

```bash
# Report what would be migrated, without changing anything
bin/console swkweb:content-manager:migrate-netzp-blog --dry-run

# Migrate
bin/console swkweb:content-manager:migrate-netzp-blog
bin/console cache:clear
```

| Option | Description |
| --- | --- |
| `--dry-run` | Only report what would be migrated |
| `--category=<id>` | Category for posts without a category and parent of the created categories. Defaults to the "navigation category" of NetzpBlog6 |
| `--sales-channel=<id>` | Sales channel the articles are visible in, can be given several times. Defaults to all storefront sales channels |
| `--overwrite` | Migrate posts and authors again that were already migrated, overwriting changes made since |
| `--force` | Migrate without confirmation, required in non-interactive mode |

The migration runs in a single transaction. Posts that were already migrated are skipped, so it can be repeated after new posts were written in NetzpBlog6.

Afterwards the migration deactivates NetzpBlog6: while it is active, saving a post or rebuilding the indexes (`dal:refresh:index`) takes the SEO URLs of the migrated articles back to the posts. To migrate posts written later, activate NetzpBlog6 only to write them, without editing migrated posts, and run the migration again.

Once the result is checked in the storefront, uninstall NetzpBlog6 and this plugin.

## What is migrated

| NetzpBlog6 | Content Manager |
| --- | --- |
| Post | Article with the same id: title, text, teaser as description, meta title and description, image, author, products, custom fields |
| Post date, "show from" | Publication date: the later of both. Posts that NetzpBlog6 already shows despite a post date in the future are published at the time of the migration |
| "Show until" | Not migrated, as articles have no publication end date: posts past it are migrated inactive |
| Sticky posts | Custom field `swkweb_netzp_blog_pin_priority`, listed first by the sorting `netzp-pinned` |
| Product stream | Per-article override of the product slider in the article layout |
| Image gallery | Per-article override of an image gallery in a variant of the article layout |
| Items | Appended to the article text, if the post layout shows them. Their images are also selected in the custom field `swkweb_netzp_blog_item_media`, so cleaning up unused media keeps them |
| Additional content, slug, canonical URL | Custom field set `swkweb_netzp_blog` |
| Custom field sets of posts, authors and categories | Moved to articles, authors and categories: Shopware cannot clean up unused media while media fields are assigned to the deactivated NetzpBlog6 |
| Author | Author with the same id |
| Category | Its navigation category, or a new hidden category below the blog category. Article listings only show the articles of their own category, so posts of navigation categories outside the blog category are also assigned to the blog category, with the navigation category as main category for the breadcrumb |
| Category restricted to a sales channel | Article visibility |
| Blog post layout | Copy of the layout as article layout, with the blog elements replaced by Content Manager and Shopware elements |
| Blog listing and teaser elements | Converted in place into the Content Manager listing and teaser elements |
| SEO URLs | Kept, now pointing to the articles |
| Media folder | Kept, detached from NetzpBlog6 so its uninstallation does not delete it |

## What is not migrated

The command lists these as warnings, with the affected posts, layouts and settings:

- Tags, the RSS feed, and the category, tag and author filters of blog listings
- Preview images that differ from the post image, and post images that are videos or other files
- Restrictions to customer groups or logged-in customers: affected posts are migrated inactive, so they do not become public
- Canonical URLs pointing elsewhere, social sharing buttons, custom Twig listing templates and Twig code in post texts
- Layouts configured for single sales channels, and teasers showing a single post or the posts of one author
