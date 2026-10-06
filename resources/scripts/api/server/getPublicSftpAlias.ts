import http from '@/api/http';

interface PublicSftpAliasResponse {
    data: {
        username: string;
    };
}

export default (server: string): Promise<string> =>
    http
        .post<PublicSftpAliasResponse>(`/api/client/servers/${server}/settings/sftp-alias`)
        .then(({ data }) => data.data.username);
