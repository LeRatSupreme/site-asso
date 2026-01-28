import { Metadata } from 'next'
import { RegisterForm } from './RegisterForm'

export const metadata: Metadata = {
  title: 'Inscription',
  description: 'Créez votre compte',
}

export default function RegisterPage() {
  return <RegisterForm />
}
