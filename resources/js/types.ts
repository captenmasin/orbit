export interface Repository {
    id: string;
    name: string;
    remote_url: string;
    web_url?: string;
}

export interface ProjectFolder {
    id: string;
    path: string;
    repository_id: string | null;
    availability?: string;
    git_state?: string;
    branch?: string | null;
    last_commit_hash?: string | null;
    last_commit_at?: string | null;
    scanned_at?: string | null;
    git_root?: string | null;
    git_remote?: string | null;
    commit_subject?: string | null;
    scan_state?: string;
    scan_error?: string | null;
    scan_attempted_at?: string | null;
    package_roots?: PackageRoot[];
}

export interface DependencyEntry {
    name: string;
    scope: string;
    required?: string;
    version?: string | null;
    location?: string | null;
    link?: string | null;
}

export interface DependencySource {
    file: string;
    state: string;
    scanned_at: string | null;
    entries: DependencyEntry[];
    requirements: Record<string, string>;
    lockfile_version?: number;
}

export interface RuntimeResult {
    tool: string;
    path: string | null;
    state: string;
    version: string | null;
    probe_directory: string;
    scanned_at: string | null;
    last_success?: Pick<RuntimeResult, 'version' | 'path' | 'probe_directory' | 'scanned_at'> | null;
}

export interface PackageRoot {
    id: string;
    project_folder_id: string;
    relative_path: string;
    executable_overrides: Record<string, string> | null;
    revision: number;
    scan_state: string;
    scan_error: string | null;
    scanned_at: string | null;
    scan_attempted_at: string | null;
    snapshot: {
        version: number;
        path: string;
        files: Record<string, DependencySource>;
        unsupported_lockfiles: string[];
        runtimes: Record<string, RuntimeResult>;
    } | null;
}

export interface ProjectLink {
    id: string;
    label: string;
    url: string;
    category?: string | null;
    icon?: string | null;
}

export interface FolderPreview {
    path: string;
    name: string;
    description: string | null;
    remote_url: string | null;
    git_state: string;
    branch: string | null;
    last_commit_hash: string | null;
    last_commit_at: string | null;
    scanned_at: string;
    warnings: string[];
}

export interface Project {
    id: string;
    name: string;
    description: string | null;
    status: string;
    revision: number;
    icon_type: 'initials' | 'emoji' | 'image';
    icon_emoji: string | null;
    icon_url: string | null;
    previous_status: string | null;
    archived_at: string | null;
    tags: { id: number; name: string }[];
    repositories: Repository[];
    folders: ProjectFolder[];
    links: ProjectLink[];
    repositories_count?: number;
    folders_count?: number;
    last_commit_at: string | null;
    board_columns?: BoardColumn[];
}

export interface BoardTask {
    id: string;
    board_column_id: string;
    title: string;
    description: string | null;
    description_html: string;
    attachments: { id: string; name: string; size: number }[];
    position: number;
}

export interface BoardColumn {
    id: string;
    name: string;
    position: number;
    tasks: BoardTask[];
}

export interface ProjectPage {
    data: Project[];
    current_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

export interface CatalogFilters {
    q: string;
    status: string;
    tag: string;
    sort: string;
}
