# SistemaISP

Sistema web para la administración de un proveedor de Internet (ISP), con integración futura a MikroTik.

## Tecnologías

### Frontend

- React 19 y TypeScript
- Vite y Tailwind CSS
- React Router, Axios y TanStack Query
- React Hook Form y Zod

### Backend

- Laravel 12 y PHP 8.3+
- Laravel Sanctum
- Eloquent ORM
- PostgreSQL

El backend contempla actualmente un único administrador. No incluye facturación, tickets, roles ni auditoría.

## Arquitectura

```text
React -> API REST Laravel -> Eloquent -> PostgreSQL
                              |
                              -> MikroTik RouterOS API (futuro)
```

## Inicio rápido

### Frontend

```bash
cd frontend
npm install
npm run dev
```

### Backend

Consulta las instrucciones y endpoints en [`backend/README.md`](backend/README.md).
