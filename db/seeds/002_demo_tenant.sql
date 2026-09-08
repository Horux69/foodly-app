-- =========================================================
-- Tenant de demostracion: comida rapida con prepago.
-- Solo para desarrollo local.
-- =========================================================

DO $$
DECLARE
  t_id UUID;
  b_id UUID;
  tax_id UUID;
  role_admin UUID;
  cat_id UUID;
  st_pend UUID; st_paid UUID; st_prep UUID; st_ready UUID; st_done UUID; st_canc UUID;
BEGIN
  INSERT INTO tenants (name, business_type, currency, settings)
  VALUES ('Burger Demo', 'fast_food', 'COP',
    '{"channels": ["counter","delivery"], "uses_tables": false, "asks_tip": false}')
  RETURNING id INTO t_id;

  INSERT INTO branches (tenant_id, name, code, address)
  VALUES (t_id, 'Sede Centro', 'CEN', 'Calle 10 #5-20')
  RETURNING id INTO b_id;

  INSERT INTO tax_rates (tenant_id, name, rate, included_in_price, is_default)
  VALUES (t_id, 'Impoconsumo 8%', 0.0800, true, true)
  RETURNING id INTO tax_id;

  -- Estados del flujo de comida rapida (prepago)
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order, is_initial)
    VALUES (t_id, 'pending', 'Pendiente', 'new', 1, true) RETURNING id INTO st_pend;
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order)
    VALUES (t_id, 'paid', 'Pagado', 'new', 2) RETURNING id INTO st_paid;
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order)
    VALUES (t_id, 'preparing', 'Preparación', 'kitchen', 3) RETURNING id INTO st_prep;
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order)
    VALUES (t_id, 'ready', 'Listo', 'ready', 4) RETURNING id INTO st_ready;
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order, is_final)
    VALUES (t_id, 'delivered', 'Entregado', 'completed', 5, true) RETURNING id INTO st_done;
  INSERT INTO order_statuses (tenant_id, code, name, category, sort_order, is_final)
    VALUES (t_id, 'cancelled', 'Cancelado', 'cancelled', 9, true) RETURNING id INTO st_canc;

  INSERT INTO order_status_transitions (from_status_id, to_status_id, required_permission) VALUES
    (st_pend,  st_paid,  'payments.register'),
    (st_paid,  st_prep,  'orders.advance_kitchen'),
    (st_prep,  st_ready, 'orders.advance_kitchen'),
    (st_ready, st_done,  'orders.advance_kitchen'),
    (st_pend,  st_canc,  'orders.cancel'),
    (st_paid,  st_canc,  'orders.cancel');

  -- Rol admin con todos los permisos
  INSERT INTO roles (tenant_id, code, name, is_system)
  VALUES (t_id, 'admin', 'Administrador', true) RETURNING id INTO role_admin;

  INSERT INTO role_permissions (role_id, permission_id)
  SELECT role_admin, id FROM permissions;

  -- Usuario admin. Password: admin123 (cambiar en cualquier entorno real)
  INSERT INTO users (tenant_id, branch_id, role_id, name, email, password_hash)
  VALUES (t_id, b_id, role_admin, 'Admin Demo', 'admin@demo.local',
          '$2b$12$6Uu.8PPEP97PeEmhjuC5te6cXlQ2n7VXZ72gnEvP8cW8DAf9LbvQy');

  -- Horario: lunes a domingo 10:00 - 22:00
  INSERT INTO branch_schedules (branch_id, weekday, opens_at, closes_at)
  SELECT b_id, d, '10:00', '22:00' FROM generate_series(0, 6) AS d;

  -- Menu minimo
  INSERT INTO menu_categories (tenant_id, name, sort_order)
  VALUES (t_id, 'Hamburguesas', 1) RETURNING id INTO cat_id;

  INSERT INTO menu_items (category_id, tax_rate_id, name, base_price, prep_minutes)
  VALUES
    (cat_id, tax_id, 'Hamburguesa clásica', 18000, 10),
    (cat_id, tax_id, 'Hamburguesa doble carne', 26000, 14);
END $$;
