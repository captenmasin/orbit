import type { LucideIcon } from '@lucide/vue';
import { ActivityIcon, BookOpenIcon, ChartNoAxesCombinedIcon, CreditCardIcon, GlobeIcon, InboxIcon, KeyRoundIcon, MailIcon, NetworkIcon, PanelsTopLeftIcon, RocketIcon, ServerIcon, ShieldIcon, UsersIcon } from '@lucide/vue';

const categoryIcons = new Map<string, LucideIcon>([
    { icon: ChartNoAxesCombinedIcon, aliases: ['analytics', 'metrics', 'statistics', 'stats', 'insights', 'reporting', 'reports'] },
    { icon: UsersIcon, aliases: ['social', 'social media', 'social network', 'social networks', 'community', 'communities'] },
    { icon: CreditCardIcon, aliases: ['billing', 'payment', 'payments', 'finance', 'finances', 'invoice', 'invoices', 'invoicing', 'subscription', 'subscriptions'] },
    { icon: BookOpenIcon, aliases: ['doc', 'docs', 'document', 'documents', 'documentation', 'guide', 'guides', 'reference', 'references', 'wiki', 'knowledge base', 'handbook'] },
    { icon: ShieldIcon, aliases: ['security', 'cybersecurity', 'cyber security', 'protection'] },
    { icon: MailIcon, aliases: ['email', 'emails', 'e mail', 'e mails', 'mail'] },
    { icon: InboxIcon, aliases: ['inbox', 'inboxes'] },
    { icon: NetworkIcon, aliases: ['infrastructure', 'infra', 'network', 'networking'] },
    { icon: RocketIcon, aliases: ['deployment', 'deployments', 'deploy', 'deploys', 'release', 'releases', 'ci/cd', 'ci cd', 'continuous integration', 'continuous delivery'] },
    { icon: KeyRoundIcon, aliases: ['oauth', 'oauth2', 'oauth 2', 'oauth2.0', 'oauth 2.0', 'auth', 'authentication', 'authorization', 'authorisation', 'login', 'log in', 'sign in', 'sso', 'single sign on', 'identity'] },
    { icon: ActivityIcon, aliases: ['monitoring', 'observability', 'uptime', 'health checks', 'logging', 'logs'] },
    { icon: ServerIcon, aliases: ['hosting', 'host', 'hosts', 'server', 'servers', 'web hosting', 'cloud hosting'] },
    { icon: GlobeIcon, aliases: ['domain', 'domains', 'domain name', 'domain names', 'dns', 'website', 'websites', 'web site', 'web sites', 'site', 'sites'] },
    { icon: PanelsTopLeftIcon, aliases: ['browser', 'browsers', 'web browser', 'web browsers', 'browser renderer', 'browser rendering', 'browser automation', 'headless browser', 'renderer', 'renderers', 'rendering'] },
].flatMap(({ icon, aliases }) => aliases.map(alias => [alias, icon] as const)));

export function linkCategoryIcon(category: string): LucideIcon | undefined {
    return categoryIcons.get(category.trim().toLowerCase().replace(/[\s_-]+/g, ' '));
}
