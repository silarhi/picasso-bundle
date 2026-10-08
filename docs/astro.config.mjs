import { defineSilarhiDocs } from '@silarhi/docs-kit'

export default defineSilarhiDocs({
    slug: 'picasso-bundle',
    title: 'Picasso Bundle',
    description: 'The missing image component for Symfony: AVIF, WebP, responsive srcset, blur placeholders and lazy loading from one line of Twig.',
    sidebar: [
        { label: 'Getting started', items: [{ autogenerate: { directory: 'getting-started' } }] },
        { label: 'Guides', items: [{ autogenerate: { directory: 'guides' } }] },
        { label: 'Image sources', items: [{ autogenerate: { directory: 'sources' } }] },
        { label: 'Transformers', items: [{ autogenerate: { directory: 'transformers' } }] },
        { label: 'Reference', items: [{ autogenerate: { directory: 'reference' } }] },
        { label: 'Concepts', items: [{ autogenerate: { directory: 'concepts' } }] },
        { label: 'Upgrade', items: [{ autogenerate: { directory: 'upgrade' } }] },
    ],
})
